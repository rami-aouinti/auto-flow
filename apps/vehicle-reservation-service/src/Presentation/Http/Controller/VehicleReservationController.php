<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\VehicleReservation\Domain\VehicleReservation;
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

#[Route('/api/v1/reservations')]
final readonly class VehicleReservationController
{
    public function __construct(private Connection $db)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->read($request, 'reservations:read', function (Connection $db, string $tenant) use ($request): JsonResponse {
            $limit = max(1, min(100, $request->query->getInt('limit', 20)));
            $offset = max(0, $request->query->getInt('offset', 0));
            $status = $request->query->get('status');
            $where = ['tenant_id = :tenant'];
            $params = ['tenant' => $tenant, 'limit' => $limit, 'offset' => $offset];
            if ($status !== null) {
                $allowed = ['held', 'confirmed', 'expired', 'released', 'fulfilled'];
                if (!is_string($status) || !in_array($status, $allowed, true)) {
                    return $this->problem(400, 'Unknown reservation status filter.');
                }
                $where[] = 'status = :status';
                $params['status'] = $status;
            }
            $filter = implode(' AND ', $where);
            $rows = $db->fetchAllAssociative(
                "SELECT reservation_id, vehicle_unit_id, vehicle_variant_id, order_id, status, expires_at, confirmed_at, released_at, fulfilled_at, version, created_at, updated_at
                 FROM vehicle_reservations WHERE $filter ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
                $params,
            );
            $total = (int) $db->fetchOne(
                "SELECT COUNT(*) FROM vehicle_reservations WHERE $filter",
                array_diff_key($params, ['limit' => true, 'offset' => true]),
            );

            return new JsonResponse(['items' => array_map($this->present(...), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
        });
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'reservations:write');
            $tenant = $this->tenant($principal);
            $payload = $this->body($request);
            $allowed = ['vehicleUnitId', 'vehicleVariantId', 'orderId', 'expiresAt'];
            if (array_diff(array_keys($payload), $allowed) !== [] || array_diff($allowed, array_keys($payload)) !== []) {
                return $this->problem(400, 'Required fields are vehicleUnitId, vehicleVariantId, orderId and expiresAt; unknown fields are not accepted.');
            }
            foreach (['vehicleUnitId', 'vehicleVariantId', 'orderId'] as $field) {
                if (!is_string($payload[$field]) || !Uuid::isValid($payload[$field])) {
                    return $this->problem(400, $field . ' must be a UUID.');
                }
            }
            if (!is_string($payload['expiresAt'])) {
                return $this->problem(400, 'expiresAt must be an ISO-8601 timestamp.');
            }
            try {
                $expiresAt = new \DateTimeImmutable($payload['expiresAt']);
            } catch (\Throwable) {
                return $this->problem(400, 'expiresAt must be a valid ISO-8601 timestamp.');
            }
            $idempotencyKey = $this->idempotencyKey($request);
            if ($idempotencyKey === null) {
                return $this->problem(400, 'A valid Idempotency-Key header is required.');
            }
            $requestHash = hash('sha256', json_encode(['command' => 'create', 'payload' => $payload], JSON_THROW_ON_ERROR));

            return $this->db->transactional(function (Connection $db) use ($principal, $tenant, $payload, $expiresAt, $idempotencyKey, $requestHash): JsonResponse {
                $this->tenantContext($db, $tenant);
                $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $tenant . ':' . $principal->subject . ':' . $idempotencyKey]);
                $previous = $this->findIdempotency($db, $tenant, $principal->subject, $idempotencyKey);
                if ($previous !== null) {
                    return $this->replay($previous, $requestHash);
                }

                $id = Uuid::v7()->toRfc4122();
                $reservation = new VehicleReservation(
                    $id,
                    $tenant,
                    $payload['vehicleUnitId'],
                    $payload['vehicleVariantId'],
                    $payload['orderId'],
                    $expiresAt,
                );
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $db->insert('vehicle_reservations', [
                    'tenant_id' => $tenant,
                    'reservation_id' => $id,
                    'vehicle_unit_id' => $payload['vehicleUnitId'],
                    'vehicle_variant_id' => $payload['vehicleVariantId'],
                    'order_id' => $payload['orderId'],
                    'status' => $reservation->status->value,
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                ]);
                $response = [
                    'id' => $id,
                    'tenantId' => $tenant,
                    'vehicleUnitId' => $payload['vehicleUnitId'],
                    'vehicleVariantId' => $payload['vehicleVariantId'],
                    'orderId' => $payload['orderId'],
                    'status' => 'held',
                    'expiresAt' => $expiresAt->format(DATE_ATOM),
                    'confirmedAt' => null,
                    'releasedAt' => null,
                    'fulfilledAt' => null,
                    'version' => 1,
                    'createdAt' => $now->format(DATE_ATOM),
                    'updatedAt' => $now->format(DATE_ATOM),
                ];
                $this->recordEvent($db, $tenant, $id, $principal->subject, 'ReservationHeld', 'reservation.held.v1', $response);
                $this->saveIdempotency($db, $tenant, $principal->subject, $idempotencyKey, $requestHash, $id, $response);

                return new JsonResponse($response, 201, ['ETag' => '"1"', 'Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (InvalidArgumentException|JsonException $exception) {
            return $this->problem(400, $exception->getMessage());
        } catch (DomainException $exception) {
            return $this->problem(422, $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'The stock unit or order already has an active reservation.');
        } catch (Throwable) {
            return $this->problem(503, 'Reservation could not be created.');
        }
    }

    #[Route('/{id}', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function get(Request $request, string $id): JsonResponse
    {
        return $this->read($request, 'reservations:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            if (!Uuid::isValid($id)) {
                return $this->problem(400, 'Reservation id must be a UUID.');
            }
            $row = $db->fetchAssociative(
                'SELECT reservation_id, vehicle_unit_id, vehicle_variant_id, order_id, status, expires_at, confirmed_at, released_at, fulfilled_at, version, created_at, updated_at
                 FROM vehicle_reservations WHERE tenant_id = :tenant AND reservation_id = :id',
                ['tenant' => $tenant, 'id' => $id],
            );
            if ($row === false) {
                return $this->problem(404, 'Reservation not found.');
            }

            return new JsonResponse($this->present($row), 200, ['ETag' => '"' . (int) $row['version'] . '"', 'Cache-Control' => 'no-store']);
        });
    }

    #[Route('/{id}/history', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function history(Request $request, string $id): JsonResponse
    {
        return $this->read($request, 'reservations:read', function (Connection $db, string $tenant) use ($id): JsonResponse {
            $rows = $db->fetchAllAssociative(
                'SELECT event_id, actor_id, event_type, payload, occurred_at
                 FROM vehicle_reservation_audit_events
                 WHERE tenant_id = :tenant AND reservation_id = :id
                 ORDER BY occurred_at DESC LIMIT 100',
                ['tenant' => $tenant, 'id' => $id],
            );
            foreach ($rows as &$row) {
                $row['payload'] = is_string($row['payload']) ? json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR) : $row['payload'];
            }
            unset($row);

            return new JsonResponse(['items' => $rows]);
        });
    }

    #[Route('/{id}/{action}', requirements: ['id' => '[0-9a-fA-F-]{36}', 'action' => 'confirm|release|fulfill'], methods: ['POST'])]
    public function transition(Request $request, string $id, string $action): JsonResponse
    {
        $scope = $action === 'fulfill' ? 'reservations:manage' : 'reservations:write';
        try {
            $principal = $this->principal($request, $scope);
            $tenant = $this->tenant($principal);
            if (!Uuid::isValid($id)) {
                return $this->problem(400, 'Reservation id must be a UUID.');
            }
            if ($this->body($request) !== []) {
                return $this->problem(400, 'This command does not accept a request body.');
            }
            $idempotencyKey = $this->idempotencyKey($request);
            if ($idempotencyKey === null) {
                return $this->problem(400, 'A valid Idempotency-Key header is required.');
            }
            $ifMatch = trim((string) $request->headers->get('If-Match', ''));
            if (!preg_match('/^"([1-9][0-9]*)"$/D', $ifMatch, $match)) {
                return $this->problem($ifMatch === '' ? 428 : 400, 'If-Match must contain the current quoted reservation version.');
            }
            $version = (int) $match[1];
            $requestHash = hash('sha256', json_encode(['action' => $action, 'reservationId' => $id], JSON_THROW_ON_ERROR));

            return $this->db->transactional(function (Connection $db) use ($principal, $tenant, $id, $action, $idempotencyKey, $requestHash, $version): JsonResponse {
                $this->tenantContext($db, $tenant);
                $previous = $this->findIdempotency($db, $tenant, $principal->subject, $idempotencyKey);
                if ($previous !== null) {
                    return $this->replay($previous, $requestHash);
                }
                $row = $db->fetchAssociative(
                    'SELECT * FROM vehicle_reservations WHERE tenant_id = :tenant AND reservation_id = :id FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $id],
                );
                if ($row === false) {
                    return $this->problem(404, 'Reservation not found.');
                }
                if ((int) $row['version'] !== $version) {
                    return $this->problem(412, 'Reservation has changed. Fetch the latest representation and retry.');
                }

                if (in_array($action, ['confirm', 'fulfill'], true)) {
                    $stock = $db->fetchAssociative(
                        'SELECT status, inventory_status, reservation_id
                         FROM vehicle_stock_units
                         WHERE tenant_id = :tenant AND vehicle_unit_id = :unit
',
                        ['tenant' => $tenant, 'unit' => $row['vehicle_unit_id']],
                    );
                    if ($stock === false
                        || $stock['status'] !== 'reserved'
                        || $stock['inventory_status'] !== 'available'
                        || (string) $stock['reservation_id'] !== $id
                    ) {
                        throw new DomainException('The physical vehicle is no longer available in Inventory.');
                    }
                }

                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $status = (string) $row['status'];
                $changes = [];
                if ($action === 'confirm') {
                    if ($status !== 'held') {
                        throw new DomainException('Only a held reservation can be confirmed.');
                    }
                    if ($now >= new \DateTimeImmutable($row['expires_at'])) {
                        throw new DomainException('An expired reservation cannot be confirmed.');
                    }
                    $status = 'confirmed';
                    $changes['confirmed_at'] = $now->format(DATE_ATOM);
                } elseif ($action === 'release') {
                    if (!in_array($status, ['held', 'confirmed'], true)) {
                        throw new DomainException('Only an active reservation can be released.');
                    }
                    $status = 'released';
                    $changes['released_at'] = $now->format(DATE_ATOM);
                } else {
                    if ($status !== 'confirmed') {
                        throw new DomainException('Only a confirmed reservation can be fulfilled.');
                    }
                    $status = 'fulfilled';
                    $changes['fulfilled_at'] = $now->format(DATE_ATOM);
                }
                $sets = ['status = :status', 'version = version + 1', 'updated_at = CURRENT_TIMESTAMP'];
                $params = ['status' => $status, 'tenant' => $tenant, 'id' => $id, 'version' => $version];
                foreach ($changes as $column => $value) {
                    $sets[] = $column . ' = :' . $column;
                    $params[$column] = $value;
                }
                $db->executeStatement(
                    'UPDATE vehicle_reservations SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant AND reservation_id = :id AND version = :version',
                    $params,
                );
                $updated = $db->fetchAssociative(
                    'SELECT reservation_id, vehicle_unit_id, vehicle_variant_id, order_id, status, expires_at, confirmed_at, released_at, fulfilled_at, version, created_at, updated_at
                     FROM vehicle_reservations WHERE tenant_id = :tenant AND reservation_id = :id',
                    ['tenant' => $tenant, 'id' => $id],
                );
                $response = $this->present($updated);
                $eventName = match ($action) {
                    'confirm' => 'ReservationConfirmed',
                    'release' => 'ReservationReleased',
                    default => 'ReservationFulfilled',
                };
                $eventType = match ($action) {
                    'confirm' => 'reservation.confirmed.v1',
                    'release' => 'reservation.released.v1',
                    default => 'reservation.fulfilled.v1',
                };
                $this->recordEvent($db, $tenant, $id, $principal->subject, $eventName, $eventType, $response);
                $this->saveIdempotency($db, $tenant, $principal->subject, $idempotencyKey, $requestHash, $id, $response);

                return new JsonResponse($response, 200, ['ETag' => '"' . (int) $response['version'] . '"', 'Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (DomainException $exception) {
            return $this->problem(409, $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'Reservation conflicts with another active stock allocation.');
        } catch (Throwable) {
            return $this->problem(503, 'Reservation command could not be completed.');
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
            return $this->problem(503, 'Reservation data is temporarily unavailable.');
        }
    }

    private function principal(Request $request, string $scope): AuthenticatedPrincipal
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope($scope)) {
            throw new AccessDeniedHttpException('Required reservation permission is missing.');
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

    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));

        return $key !== '' && strlen($key) <= 128 ? $key : null;
    }

    private function findIdempotency(Connection $db, string $tenant, string $actor, string $key): ?array
    {
        $row = $db->fetchAssociative(
            'SELECT request_hash, response_body FROM vehicle_reservation_idempotency
             WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
            ['tenant' => $tenant, 'actor' => $actor, 'key' => $key],
        );

        return $row === false ? null : $row;
    }

    private function replay(array $previous, string $requestHash): JsonResponse
    {
        if (!hash_equals(trim((string) $previous['request_hash']), $requestHash)) {
            return $this->problem(409, 'Idempotency-Key was already used for another command or payload.');
        }
        $body = is_string($previous['response_body'])
            ? json_decode($previous['response_body'], true, 512, JSON_THROW_ON_ERROR)
            : $previous['response_body'];

        return new JsonResponse($body, 200, ['ETag' => '"' . (int) ($body['version'] ?? 1) . '"', 'Cache-Control' => 'no-store']);
    }

    private function saveIdempotency(Connection $db, string $tenant, string $actor, string $key, string $hash, string $id, array $response): void
    {
        $db->insert('vehicle_reservation_idempotency', [
            'tenant_id' => $tenant,
            'actor_id' => $actor,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'reservation_id' => $id,
            'response_body' => json_encode($response, JSON_THROW_ON_ERROR),
        ]);
    }

    private function recordEvent(Connection $db, string $tenant, string $id, string $actor, string $name, string $type, array $data): void
    {
        $eventId = Uuid::v7()->toRfc4122();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $envelope = [
            'eventId' => $eventId,
            'eventType' => $type,
            'schemaVersion' => 1,
            'producer' => 'vehicle-reservation-service',
            'aggregate' => ['type' => 'VehicleReservation', 'id' => $id],
            'tenantId' => $tenant,
            'occurredAt' => $now,
            'data' => $data,
        ];
        $json = json_encode($envelope, JSON_THROW_ON_ERROR);
        $db->insert('vehicle_reservation_audit_events', [
            'tenant_id' => $tenant,
            'event_id' => $eventId,
            'reservation_id' => $id,
            'actor_id' => $actor,
            'event_type' => $name,
            'payload' => $json,
            'occurred_at' => $now,
        ]);
        $db->insert('vehicle_reservation_outbox', [
            'tenant_id' => $tenant,
            'event_id' => $eventId,
            'reservation_id' => $id,
            'event_type' => $type,
            'payload' => $json,
            'occurred_at' => $now,
        ]);
    }

    private function present(array $row): array
    {
        return [
            'id' => (string) $row['reservation_id'],
            'vehicleUnitId' => (string) $row['vehicle_unit_id'],
            'vehicleVariantId' => (string) $row['vehicle_variant_id'],
            'orderId' => (string) $row['order_id'],
            'status' => (string) $row['status'],
            'expiresAt' => (new \DateTimeImmutable($row['expires_at']))->format(DATE_ATOM),
            'confirmedAt' => $row['confirmed_at'] === null ? null : (new \DateTimeImmutable($row['confirmed_at']))->format(DATE_ATOM),
            'releasedAt' => $row['released_at'] === null ? null : (new \DateTimeImmutable($row['released_at']))->format(DATE_ATOM),
            'fulfilledAt' => $row['fulfilled_at'] === null ? null : (new \DateTimeImmutable($row['fulfilled_at']))->format(DATE_ATOM),
            'version' => (int) $row['version'],
            'createdAt' => (new \DateTimeImmutable($row['created_at']))->format(DATE_ATOM),
            'updatedAt' => (new \DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
        ];
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        $title = match ($status) {
            400 => 'Bad Request',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            412 => 'Precondition Failed',
            422 => 'Unprocessable Content',
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
