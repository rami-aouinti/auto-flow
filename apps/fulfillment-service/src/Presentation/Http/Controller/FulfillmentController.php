<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\FulfillmentCommandPolicy;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

#[Route('/api/v1/fulfillments')]
final readonly class FulfillmentController
{
    public function __construct(
        private Connection $db,
        private FulfillmentCommandPolicy $policy,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->read($request, 'fulfillment:read', function (Connection $db, string $tenant) use ($request): JsonResponse {
            $limit = max(1, min(100, $request->query->getInt('limit', 20)));
            $offset = max(0, $request->query->getInt('offset', 0));
            $where = ['tenant_id = :tenant'];
            $params = ['tenant' => $tenant, 'limit' => $limit, 'offset' => $offset];

            $status = $request->query->get('status');
            if ($status !== null) {
                $allowed = ['awaiting_prerequisites', 'ready_for_preparation', 'preparing', 'ready_for_dispatch', 'scheduled', 'in_transit', 'delivered', 'delivery_failed', 'cancelled'];
                if (!is_string($status) || !in_array($status, $allowed, true)) {
                    return $this->problem(400, 'Unknown fulfillment status filter.');
                }
                $where[] = 'status = :status';
                $params['status'] = $status;
            }

            $orderId = $request->query->get('orderId');
            if ($orderId !== null) {
                if (!is_string($orderId) || !Uuid::isValid($orderId)) {
                    return $this->problem(400, 'orderId must be a UUID.');
                }
                $where[] = 'order_id = :order';
                $params['order'] = $orderId;
            }

            $filter = implode(' AND ', $where);
            $items = $db->fetchAllAssociative(
                "SELECT fulfillment_id, order_id, status, payment_captured_at, contract_signed_at, scheduled_for, carrier, tracking_reference, dispatched_at, delivered_at, recipient_name, delivery_failure_reason, version, created_at, updated_at
                 FROM fulfillment_records WHERE $filter ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
                $params,
            );
            $total = (int) $db->fetchOne(
                "SELECT COUNT(*) FROM fulfillment_records WHERE $filter",
                array_diff_key($params, ['limit' => true, 'offset' => true]),
            );

            return new JsonResponse(['items' => array_map($this->present(...), $items), 'total' => $total, 'limit' => $limit, 'offset' => $offset], 200, $this->headers());
        });
    }

    #[Route('/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function get(Request $request, string $id): JsonResponse
    {
        return $this->read($request, 'fulfillment:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            $row = $this->load($db, $tenant, $id);
            if ($row === null) {
                return $this->problem(404, 'Fulfillment not found.');
            }

            return new JsonResponse($this->present($row), 200, $this->headers((int) $row['version']));
        });
    }

    #[Route('/{id}/history', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function history(Request $request, string $id): JsonResponse
    {
        return $this->read($request, 'fulfillment:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            if ($this->load($db, $tenant, $id) === null) {
                return $this->problem(404, 'Fulfillment not found.');
            }
            $items = $db->fetchAllAssociative(
                'SELECT event_id, actor_id, event_type, payload, occurred_at
                 FROM fulfillment_audit_events
                 WHERE tenant_id = :tenant AND fulfillment_id = :id
                 ORDER BY occurred_at DESC LIMIT 100',
                ['tenant' => $tenant, 'id' => $id],
            );
            foreach ($items as &$item) {
                $item['payload'] = is_string($item['payload'])
                    ? json_decode($item['payload'], true, 512, JSON_THROW_ON_ERROR)
                    : $item['payload'];
            }
            unset($item);

            return new JsonResponse(['items' => $items], 200, $this->headers());
        });
    }

    #[Route('/{id}/preparation/start', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function startPreparation(Request $request, string $id): JsonResponse
    {
        return $this->command($request, $id, 'fulfillment:prepare', 'start-preparation');
    }

    #[Route('/{id}/preparation/complete', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function completePreparation(Request $request, string $id): JsonResponse
    {
        return $this->command($request, $id, 'fulfillment:prepare', 'complete-preparation');
    }

    #[Route('/{id}/schedule', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function schedule(Request $request, string $id): JsonResponse
    {
        return $this->command($request, $id, 'fulfillment:schedule', 'schedule');
    }

    #[Route('/{id}/dispatch', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function dispatch(Request $request, string $id): JsonResponse
    {
        return $this->command($request, $id, 'fulfillment:dispatch', 'dispatch');
    }

    #[Route('/{id}/delivery/confirm', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function confirmDelivery(Request $request, string $id): JsonResponse
    {
        return $this->command($request, $id, 'fulfillment:deliver', 'confirm-delivery');
    }

    #[Route('/{id}/delivery/fail', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function failDelivery(Request $request, string $id): JsonResponse
    {
        return $this->command($request, $id, 'fulfillment:deliver', 'fail-delivery');
    }

    #[Route('/{id}/cancel', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->command($request, $id, 'fulfillment:cancel', 'cancel');
    }

    private function command(Request $request, string $id, string $scope, string $command): JsonResponse
    {
        try {
            $principal = $this->principal($request, $scope);
            $tenant = $this->tenant($principal);
            if (!Uuid::isValid($id)) {
                return $this->problem(400, 'Fulfillment id must be a UUID.');
            }

            $payload = $this->body($request);
            $allowedPayload = match ($command) {
                'schedule' => ['scheduledFor', 'carrier', 'trackingReference'],
                'confirm-delivery' => ['recipientName'],
                'fail-delivery' => ['reason'],
                default => [],
            };
            if (array_diff(array_keys($payload), $allowedPayload) !== []) {
                return $this->problem(400, 'The command contains unsupported payload fields.');
            }
            $idempotencyKey = trim((string) $request->headers->get('Idempotency-Key', ''));
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
                return $this->problem(400, 'A valid Idempotency-Key header is required.');
            }
            $requestHash = hash('sha256', json_encode(['command' => $command, 'payload' => $payload], JSON_THROW_ON_ERROR));
            $ifMatch = trim((string) $request->headers->get('If-Match', ''));
            if ($ifMatch === '') {
                return $this->problem(428, 'If-Match with the current ETag is required for state-changing commands.');
            }
            if (!preg_match('/^"([1-9][0-9]*)"$/D', $ifMatch, $etagMatch)) {
                return $this->problem(400, 'If-Match must contain a quoted positive fulfillment version.');
            }
            $expectedVersion = (int) $etagMatch[1];

            return $this->db->transactional(function (Connection $db) use ($principal, $tenant, $id, $command, $payload, $idempotencyKey, $requestHash, $expectedVersion): JsonResponse {
                $this->tenantContext($db, $tenant);

                $previous = $db->fetchAssociative(
                    'SELECT request_hash, response_body FROM fulfillment_idempotency_keys
                     WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $idempotencyKey],
                );
                if ($previous !== false) {
                    if (!hash_equals(trim((string) $previous['request_hash']), $requestHash)) {
                        return $this->problem(409, 'Idempotency-Key was already used for another command or payload.');
                    }
                    $body = is_string($previous['response_body'])
                        ? json_decode($previous['response_body'], true, 512, JSON_THROW_ON_ERROR)
                        : $previous['response_body'];

                    return new JsonResponse($body, 200, $this->headers(isset($body['version']) ? (int) $body['version'] : null));
                }

                $row = $db->fetchAssociative(
                    'SELECT * FROM fulfillment_records
                     WHERE tenant_id = :tenant AND fulfillment_id = :id
                     FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $id],
                );
                if ($row === false) {
                    return $this->problem(404, 'Fulfillment not found.');
                }

                // A concurrent request with the same idempotency key may have committed while this row lock was waiting.
                $previous = $db->fetchAssociative(
                    'SELECT request_hash, response_body FROM fulfillment_idempotency_keys
                     WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $idempotencyKey],
                );
                if ($previous !== false) {
                    if (!hash_equals(trim((string) $previous['request_hash']), $requestHash)) {
                        return $this->problem(409, 'Idempotency-Key was already used for another command or payload.');
                    }
                    $body = is_string($previous['response_body'])
                        ? json_decode($previous['response_body'], true, 512, JSON_THROW_ON_ERROR)
                        : $previous['response_body'];

                    return new JsonResponse($body, 200, $this->headers(isset($body['version']) ? (int) $body['version'] : null));
                }

                if ((int) $row['version'] !== $expectedVersion) {
                    return $this->problem(412, 'Fulfillment has changed. Fetch the latest representation and retry.');
                }

                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $decision = $this->policy->apply($command, $row, $payload, $now);
                $sets = ['status = :status', 'updated_at = CURRENT_TIMESTAMP', 'version = version + 1'];
                $params = [
                    'status' => $decision['status'],
                    'tenant' => $tenant,
                    'id' => $id,
                    'version' => $expectedVersion,
                ];
                foreach ($decision['changes'] as $column => $value) {
                    if (!in_array($column, ['scheduled_for', 'carrier', 'tracking_reference', 'dispatched_at', 'delivered_at', 'recipient_name', 'delivery_failure_reason'], true)) {
                        throw new \LogicException('Command policy returned an unsupported persistence field.');
                    }
                    $sets[] = $column . ' = :' . $column;
                    $params[$column] = $value;
                }

                $updated = $db->executeStatement(
                    'UPDATE fulfillment_records SET ' . implode(', ', $sets) . '
                     WHERE tenant_id = :tenant AND fulfillment_id = :id AND version = :version',
                    $params,
                );
                if ($updated !== 1) {
                    return $this->problem(412, 'Fulfillment has changed. Fetch the latest representation and retry.');
                }

                $occurredAt = $now->format(DATE_ATOM);
                $eventId = Uuid::v7()->toRfc4122();
                $eventType = 'fulfillment.' . str_replace('-', '_', $command) . '.v1';
                $eventPayload = [
                    'eventId' => $eventId,
                    'eventType' => $eventType,
                    'schemaVersion' => 1,
                    'producer' => 'fulfillment-service',
                    'aggregate' => ['type' => 'Fulfillment', 'id' => $id],
                    'tenantId' => $tenant,
                    'occurredAt' => $occurredAt,
                    'data' => [
                        'fulfillmentId' => $id,
                        'orderId' => $row['order_id'],
                        'previousStatus' => $row['status'],
                        'status' => $decision['status'],
                        'command' => $command,
                        'details' => $decision['changes'],
                    ],
                ];

                $db->insert('fulfillment_audit_events', [
                    'tenant_id' => $tenant,
                    'event_id' => $eventId,
                    'fulfillment_id' => $id,
                    'actor_id' => $principal->subject,
                    'event_type' => 'Fulfillment' . str_replace(' ', '', ucwords(str_replace('-', ' ', $command))),
                    'payload' => json_encode($eventPayload, JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt,
                ]);
                $db->insert('fulfillment_outbox', [
                    'tenant_id' => $tenant,
                    'event_id' => $eventId,
                    'fulfillment_id' => $id,
                    'event_type' => $eventType,
                    'payload' => json_encode($eventPayload, JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt,
                ]);

                $updatedRow = $this->load($db, $tenant, $id);
                if ($updatedRow === null) {
                    throw new \RuntimeException('Fulfillment disappeared after command execution.');
                }
                $responseBody = $this->present($updatedRow);
                $db->insert('fulfillment_idempotency_keys', [
                    'tenant_id' => $tenant,
                    'actor_id' => $principal->subject,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'fulfillment_id' => $id,
                    'response_body' => json_encode($responseBody, JSON_THROW_ON_ERROR),
                ]);

                return new JsonResponse($responseBody, 200, $this->headers((int) $updatedRow['version']));
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (InvalidArgumentException|JsonException $exception) {
            return $this->problem(400, $exception->getMessage());
        } catch (DomainException $exception) {
            return $this->problem(409, $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'The command conflicts with another request. Fetch the latest fulfillment and retry.');
        } catch (Throwable) {
            return $this->problem(503, 'Fulfillment command could not be completed.');
        }
    }

    private function read(Request $request, string $scope, callable $callback): JsonResponse
    {
        try {
            $principal = $this->principal($request, $scope);
            $tenant = $this->tenant($principal);

            return $this->db->transactional(function (Connection $db) use ($tenant, $callback): JsonResponse {
                $this->tenantContext($db, $tenant);

                return $callback($db, $tenant);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Fulfillment data is temporarily unavailable.');
        }
    }

    private function principal(Request $request, string $scope): AuthenticatedPrincipal
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope($scope)) {
            throw new AccessDeniedHttpException('Required fulfillment permission is missing.');
        }

        return $principal;
    }

    private function tenant(AuthenticatedPrincipal $principal): string
    {
        if (!is_string($principal->tenantId) || trim($principal->tenantId) === '') {
            throw new AccessDeniedHttpException('Tenant context is missing.');
        }

        return $principal->tenantId;
    }

    private function tenantContext(Connection $db, string $tenant): void
    {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent() === '' ? '{}' : $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new InvalidArgumentException('A JSON object is required.');
        }

        return $body;
    }

    /** @return array<string,mixed>|null */
    private function load(Connection $db, string $tenant, string $id): ?array
    {
        $row = $db->fetchAssociative(
            'SELECT fulfillment_id, order_id, status, payment_captured_at, contract_signed_at, scheduled_for, carrier, tracking_reference, dispatched_at, delivered_at, recipient_name, delivery_failure_reason, version, created_at, updated_at
             FROM fulfillment_records WHERE tenant_id = :tenant AND fulfillment_id = :id',
            ['tenant' => $tenant, 'id' => $id],
        );

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => $row['fulfillment_id'],
            'orderId' => $row['order_id'],
            'status' => $row['status'],
            'paymentCapturedAt' => $row['payment_captured_at'],
            'contractSignedAt' => $row['contract_signed_at'],
            'scheduledFor' => $row['scheduled_for'],
            'carrier' => $row['carrier'],
            'trackingReference' => $row['tracking_reference'],
            'dispatchedAt' => $row['dispatched_at'],
            'deliveredAt' => $row['delivered_at'],
            'recipientName' => $row['recipient_name'],
            'deliveryFailureReason' => $row['delivery_failure_reason'],
            'version' => (int) $row['version'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    /** @return array<string,string> */
    private function headers(?int $version = null): array
    {
        $headers = ['Cache-Control' => 'no-store'];
        if ($version !== null) {
            $headers['ETag'] = '"' . $version . '"';
        }

        return $headers;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        $title = match ($status) {
            400 => 'Bad Request',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            412 => 'Precondition Failed',
            428 => 'Precondition Required',
            default => 'Service Unavailable',
        };

        return new JsonResponse(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store'],
        );
    }
}
