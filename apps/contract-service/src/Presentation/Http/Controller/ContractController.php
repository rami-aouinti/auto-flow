<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Port\SignatureProviderInterface;
use App\Infrastructure\Document\ContractDocumentManager;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
#[Route('/api/v1/contracts')]
final readonly class ContractController
{
    public function __construct(
        private Connection $db,
        private HttpClientInterface $http,
        private string $orderServiceUrl,
        private ContractDocumentManager $documents,
        private SignatureProviderInterface $signatureProvider,
    ) {
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'contracts:write');
            $tenant = $this->tenant($principal);
            $body = $this->body($request);
            $orderId = $this->uuid($body['orderId'] ?? null, 'orderId');
            $type = $body['contractType'] ?? 'vehicle_lease';
            $version = $body['contractVersion'] ?? 1;
            if (!is_string($type) || !in_array(
                $type,
                ['vehicle_purchase', 'vehicle_lease', 'vehicle_subscription'],
                true,
            )) {
                return $this->problem(422, 'contractType is not supported.');
            }
            if (!is_int($version) || $version < 1 || $version > 100) {
                return $this->problem(422, 'contractVersion must be a positive integer.');
            }
            $key = trim((string) $request->headers->get('Idempotency-Key', ''));
            if ($key === '' || strlen($key) > 128) {
                return $this->problem(400, 'A valid Idempotency-Key is required.');
            }

            $requestHash = hash('sha256', json_encode([
                'orderId' => $orderId,
                'contractType' => $type,
                'contractVersion' => $version,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            // Replay a committed command before calling Order Service. A retry must
            // remain safe even if the order has since moved to another lifecycle state.
            $replay = $this->db->transactional(
                function (Connection $db) use ($tenant, $principal, $key, $requestHash): ?JsonResponse {
                $this->setTenant($db, $tenant);
                $existing = $db->fetchAssociative(
                    'SELECT request_hash, response_body FROM contract_idempotency_keys
                     WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $key],
                );
                if (!is_array($existing)) {
                    return null;
                }
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                    return $this->problem(409, 'Idempotency-Key was used with a different contract command.');
                }
                return new JsonResponse(
                    $this->decode($existing['response_body']),
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            });
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            // The browser never supplies legal snapshots. They are captured from the
            // authoritative Order API using the caller's verified token and tenant.
            $order = $this->fetchAcceptedOrder($request, $orderId, $tenant);
            if ($order instanceof JsonResponse) {
                return $order;
            }
            $snapshot = $this->legalSnapshot($order);

            return $this->db->transactional(function (Connection $db) use (
                $tenant, $principal, $orderId, $type, $version, $key, $requestHash, $snapshot,
            ): JsonResponse {
                $this->setTenant($db, $tenant);
                $db->executeQuery(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
                    ['lock_key' => $tenant . ':' . $principal->subject . ':' . $key],
                );
                $existing = $db->fetchAssociative(
                    'SELECT request_hash, response_body FROM contract_idempotency_keys
                     WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $key],
                );
                if (is_array($existing)) {
                    if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                        return $this->problem(409, 'Idempotency-Key was used with a different contract command.');
                    }
                    return new JsonResponse(
                        $this->decode($existing['response_body']),
                        200,
                        ['Cache-Control' => 'no-store'],
                    );
                }

                $contractId = Uuid::v7()->toRfc4122();
                $now = (new \DateTimeImmutable())->format(DATE_ATOM);
                $db->insert('contract_records', [
                    'tenant_id' => $tenant,
                    'contract_id' => $contractId,
                    'order_id' => $orderId,
                    'customer_id' => $snapshot['customerId'],
                    'contract_type' => $type,
                    'contract_version' => $version,
                    'status' => 'draft',
                    'legal_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_by' => $principal->subject,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $response = $this->find($db, $tenant, $contractId);
                $this->audit($db, $tenant, $contractId, $principal->subject, 'ContractDraftCreated', [
                    'orderId' => $orderId, 'contractType' => $type, 'contractVersion' => $version,
                ]);
                $this->outbox($db, $tenant, $contractId, 'contract.draft.created.v1', [
                    'contractId' => $contractId, 'orderId' => $orderId, 'customerId' => $snapshot['customerId'],
                    'contractType' => $type, 'contractVersion' => $version,
                ]);
                $db->insert('contract_idempotency_keys', [
                    'tenant_id' => $tenant,
                    'actor_id' => $principal->subject,
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'contract_id' => $contractId,
                    'response_body' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);

                return new JsonResponse($response, 201, ['Cache-Control' => 'no-store', 'ETag' => '"1"']);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (\InvalidArgumentException|\JsonException $exception) {
            return $this->problem(400, $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'A contract already exists for this order, type and version.');
        } catch (DomainException $exception) {
            return $this->problem(422, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Contract creation is temporarily unavailable.');
        }
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'contracts:read');
            $tenant = $this->tenant($principal);
            return $this->db->transactional(function (Connection $db) use ($request, $tenant): JsonResponse {
                $this->setTenant($db, $tenant);
                $limit = max(1, min(100, $request->query->getInt('limit', 20)));
                $offset = max(0, $request->query->getInt('offset', 0));
                $status = $request->query->get('status');
                $where = ['tenant_id = :tenant'];
                $params = ['tenant' => $tenant, 'limit' => $limit, 'offset' => $offset];
                if (is_string($status) && in_array(
                    $status,
                    ['draft', 'prepared', 'awaiting_signature', 'signed', 'cancelled', 'superseded'],
                    true,
                )) {
                    $where[] = 'status = :status';
                    $params['status'] = $status;
                }
                $predicate = implode(' AND ', $where);
                $rows = $db->fetchAllAssociative(
                    "SELECT contract_id, order_id, customer_id, contract_type, contract_version, status,
                            document_checksum, signature_provider, provider_envelope_id,
                            created_at, updated_at, prepared_at, signed_at, cancelled_at
                     FROM contract_records WHERE {$predicate}
                     ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
                    $params,
                );
                $total = (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM contract_records WHERE {$predicate}",
                    array_diff_key($params, ['limit' => true, 'offset' => true]),
                );
                return new JsonResponse(
                    ['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset],
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Contracts could not be listed.');
        }
    }

    #[Route('/{contractId}', requirements: ['contractId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function get(Request $request, string $contractId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'contracts:read');
            $tenant = $this->tenant($principal);
            return $this->db->transactional(function (Connection $db) use ($tenant, $contractId): JsonResponse {
                $this->setTenant($db, $tenant);
                $item = $this->find($db, $tenant, $contractId);
                return $item === null
                    ? $this->problem(404, 'Contract not found.')
                    : new JsonResponse($item, 200, ['Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Contract could not be retrieved.');
        }
    }

    #[Route('/{contractId}/prepare', requirements: ['contractId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function prepare(Request $request, string $contractId): JsonResponse
    {
        return $this->transition(
            $request,
            $contractId,
            'contracts:write',
            'draft',
            'prepared',
            'ContractPrepared',
            'contract.prepared.v1',
        );
    }

    #[Route('/{contractId}/cancel', requirements: ['contractId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function cancel(Request $request, string $contractId): JsonResponse
    {
        return $this->transition(
            $request,
            $contractId,
            'contracts:manage',
            ['draft', 'prepared'],
            'cancelled',
            'ContractCancelled',
            'contract.cancelled.v1',
        );
    }

    #[Route('/{contractId}/send-for-signature', requirements: ['contractId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function sendForSignature(Request $request, string $contractId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'contracts:manage');
            $tenant = $this->tenant($principal);
            $key = trim((string) $request->headers->get('Idempotency-Key', ''));
            if ($key === '' || strlen($key) > 128) {
                return $this->problem(400, 'A valid Idempotency-Key is required.');
            }
            $requestHash = hash('sha256', json_encode([
                'contractId' => $contractId,
                'action' => 'send_for_signature',
            ], JSON_THROW_ON_ERROR));

            $replay = $this->db->transactional(function (Connection $db) use (
                $tenant,
                $principal,
                $key,
                $requestHash,
            ): ?JsonResponse {
                $this->setTenant($db, $tenant);
                $existing = $db->fetchAssociative(
                    'SELECT request_hash, response_body FROM contract_transition_idempotency
                     WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $key],
                );
                if (!is_array($existing)) {
                    return null;
                }
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                    return $this->problem(409, 'Idempotency-Key was used for another contract command.');
                }

                return new JsonResponse(
                    $this->decode($existing['response_body']),
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            });
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            $contract = $this->db->transactional(function (Connection $db) use ($tenant, $contractId): ?array {
                $this->setTenant($db, $tenant);

                return $this->find($db, $tenant, $contractId);
            });
            if ($contract === null) {
                return $this->problem(404, 'Contract not found.');
            }
            if ($contract['status'] !== 'prepared') {
                return $this->problem(409, 'Only prepared contracts can be sent for signature.');
            }

            $document = $this->documents->findLatest($tenant, $contractId);
            if ($document === null) {
                return $this->problem(409, 'Generate the immutable PDF document before requesting a signature.');
            }
            $customer = $contract['legalSnapshot']['customer'] ?? [];
            if (!is_array($customer)) {
                return $this->problem(422, 'The contract snapshot does not contain customer signing details.');
            }
            $email = $customer['email'] ?? '';
            $name = $customer['displayName'] ?? $customer['name'] ?? $customer['legalName'] ?? '';
            if (!is_string($email) || !is_string($name)) {
                return $this->problem(422, 'The customer signing details are invalid.');
            }

            // The provider receives the same idempotency key. If the API process dies
            // after envelope creation but before the DB commit, a retry remains safe.
            $envelope = $this->signatureProvider->send($document['path'], $email, $name, $key);

            return $this->db->transactional(function (Connection $db) use (
                $tenant,
                $principal,
                $contractId,
                $key,
                $requestHash,
                $envelope,
            ): JsonResponse {
                $this->setTenant($db, $tenant);
                $db->executeQuery(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
                    ['lock_key' => $tenant . ':' . $principal->subject . ':' . $key],
                );
                $existing = $db->fetchAssociative(
                    'SELECT request_hash, response_body FROM contract_transition_idempotency
                     WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $key],
                );
                if (is_array($existing)) {
                    if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                        return $this->problem(409, 'Idempotency-Key was used for another contract command.');
                    }

                    return new JsonResponse(
                        $this->decode($existing['response_body']),
                        200,
                        ['Cache-Control' => 'no-store'],
                    );
                }

                $db->fetchOne(
                    'SELECT contract_id FROM contract_records
                     WHERE tenant_id = :tenant AND contract_id = :id FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $contractId],
                );
                $current = $this->find($db, $tenant, $contractId);
                if ($current === null) {
                    return $this->problem(404, 'Contract not found.');
                }
                if ($current['status'] !== 'prepared') {
                    return $this->problem(409, 'Contract state changed while the signature request was being sent.');
                }

                $timestamp = (new \\DateTimeImmutable())->format(DATE_ATOM);
                $db->update('contract_records', [
                    'status' => 'awaiting_signature',
                    'signature_provider' => $envelope['provider'],
                    'provider_envelope_id' => $envelope['envelopeId'],
                    'updated_at' => $timestamp,
                ], ['tenant_id' => $tenant, 'contract_id' => $contractId]);
                $this->audit($db, $tenant, $contractId, $principal->subject, 'ContractSentForSignature', [
                    'provider' => $envelope['provider'],
                    'envelopeId' => $envelope['envelopeId'],
                ]);
                $this->outbox($db, $tenant, $contractId, 'contract.signature.requested.v1', [
                    'contractId' => $contractId,
                    'provider' => $envelope['provider'],
                    'envelopeId' => $envelope['envelopeId'],
                ]);
                $response = $this->find($db, $tenant, $contractId);
                $db->insert('contract_transition_idempotency', [
                    'tenant_id' => $tenant,
                    'actor_id' => $principal->subject,
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'contract_id' => $contractId,
                    'response_body' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);

                return new JsonResponse($response, 202, ['Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (DomainException $exception) {
            return $this->problem(422, $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'A signature request already exists for this contract.');
        } catch (Throwable) {
            return $this->problem(503, 'The e-signature provider is unavailable or the request could not be persisted.');
        }
    }

    #[Route('/{contractId}/document', requirements: ['contractId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function generateDocument(Request $request, string $contractId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'contracts:write');
            $tenant = $this->tenant($principal);
            $document = $this->documents->generate($tenant, $principal->subject, $contractId, preg_replace('/^Bearer\s+/i', '', (string) $request->headers->get('Authorization')) ?? '');
            if ($document === null) {
                return $this->problem(404, 'Contract not found.');
            }

            unset($document['path']);

            return new JsonResponse(['document' => $document], 200, ['Cache-Control' => 'no-store']);
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (DomainException $exception) {
            return $this->problem(409, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Contract PDF could not be generated.');
        }
    }

    #[Route('/{contractId}/document', requirements: ['contractId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function downloadDocument(Request $request, string $contractId): Response
    {
        try {
            $principal = $this->principal($request, 'contracts:read');
            $tenant = $this->tenant($principal);
            $download = $this->documents->downloadLatest(
                $tenant,
                $contractId,
                preg_replace('/^Bearer\s+/i', '', (string) $request->headers->get('Authorization')) ?? '',
            );
            if ($download === null) {
                return $this->problem(404, 'Contract document not found.');
            }
            $document = $download['document'];

            return new Response($download['content'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="contract-' . $contractId . '.pdf"',
                'Cache-Control' => 'private, no-store',
                'X-Content-SHA256' => $document['checksum'],
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Contract PDF could not be retrieved or failed integrity verification.');
        }
    }

    #[Route('/{contractId}/history', requirements: ['contractId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function history(Request $request, string $contractId): JsonResponse
    {
        try {
            $principal = $this->principal($request, 'contracts:read');
            $tenant = $this->tenant($principal);
            return $this->db->transactional(function (Connection $db) use ($tenant, $contractId): JsonResponse {
                $this->setTenant($db, $tenant);
                if ($this->find($db, $tenant, $contractId) === null) {
                    return $this->problem(404, 'Contract not found.');
                }
                $items = $db->fetchAllAssociative(
                    'SELECT event_id, actor_id, event_type, payload, occurred_at
                     FROM contract_audit_events
                     WHERE tenant_id = :tenant AND contract_id = :id
                     ORDER BY occurred_at DESC LIMIT 100',
                    ['tenant' => $tenant, 'id' => $contractId],
                );
                foreach ($items as &$item) {
                    $item['payload'] = $this->decode($item['payload']);
                }
                unset($item);
                return new JsonResponse(['items' => $items], 200, ['Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Contract history could not be retrieved.');
        }
    }

    /**
     * @param string|list<string> $from
     */
    private function transition(
        Request $request,
        string $contractId,
        string $scope,
        string|array $from,
        string $to,
        string $event,
        string $eventType,
    ): JsonResponse {
        try {
            $principal = $this->principal($request, $scope);
            $tenant = $this->tenant($principal);
            $key = trim((string) $request->headers->get('Idempotency-Key', ''));
            if ($key === '' || strlen($key) > 128) {
                return $this->problem(400, 'A valid Idempotency-Key is required.');
            }
            return $this->db->transactional(
                function (Connection $db) use (
                    $tenant,
                    $principal,
                    $contractId,
                    $from,
                    $to,
                    $event,
                    $eventType,
                    $key,
                ): JsonResponse {
                $this->setTenant($db, $tenant);
                $db->executeQuery(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
                    ['lock_key' => $tenant . ':' . $principal->subject . ':' . $key],
                );
                $requestHash = hash('sha256', json_encode([
                    'contractId' => $contractId,
                    'targetStatus' => $to,
                ], JSON_THROW_ON_ERROR));
                $replay = $db->fetchAssociative(
                    'SELECT request_hash, response_body FROM contract_transition_idempotency
                     WHERE tenant_id = :tenant AND actor_id = :actor AND idempotency_key = :key',
                    ['tenant' => $tenant, 'actor' => $principal->subject, 'key' => $key],
                );
                if (is_array($replay)) {
                    if (!hash_equals((string) $replay['request_hash'], $requestHash)) {
                        return $this->problem(409, 'Idempotency-Key was used for another contract transition.');
                    }
                    return new JsonResponse(
                        $this->decode($replay['response_body']),
                        200,
                        ['Cache-Control' => 'no-store'],
                    );
                }
                $db->fetchOne(
                    'SELECT contract_id FROM contract_records
                     WHERE tenant_id = :tenant AND contract_id = :id FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $contractId],
                );
                $item = $this->find($db, $tenant, $contractId);
                if ($item === null) {
                    return $this->problem(404, 'Contract not found.');
                }
                $allowed = is_array($from) ? $from : [$from];
                if (!in_array($item['status'], $allowed, true)) {
                    return $this->problem(409, sprintf('Cannot move a %s contract to %s.', $item['status'], $to));
                }
                $timestamp = (new \DateTimeImmutable())->format(DATE_ATOM);
                $sets = ['status' => $to, 'updated_at' => $timestamp];
                if ($to === 'prepared') {
                    $sets['prepared_at'] = $timestamp;
                } else {
                    $sets['cancelled_at'] = $timestamp;
                }
                $db->update('contract_records', $sets, ['tenant_id' => $tenant, 'contract_id' => $contractId]);
                $this->audit(
                    $db,
                    $tenant,
                    $contractId,
                    $principal->subject,
                    $event,
                    ['from' => $item['status'], 'to' => $to],
                );
                $this->outbox(
                    $db,
                    $tenant,
                    $contractId,
                    $eventType,
                    ['contractId' => $contractId, 'orderId' => $item['orderId'], 'status' => $to],
                );
                $response = $this->find($db, $tenant, $contractId);
                $db->insert('contract_transition_idempotency', [
                    'tenant_id' => $tenant,
                    'actor_id' => $principal->subject,
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'contract_id' => $contractId,
                    'response_body' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
                return new JsonResponse($response, 200, ['Cache-Control' => 'no-store']);
            });
        } catch (AccessDeniedHttpException $exception) {
            return $this->problem(403, $exception->getMessage());
        } catch (Throwable) {
            return $this->problem(503, 'Contract state could not be changed.');
        }
    }

    /** @return array<string, mixed>|JsonResponse */
    private function fetchAcceptedOrder(Request $request, string $orderId, string $tenant): array|JsonResponse
    {
        $authorization = $request->headers->get('Authorization', '');
        if (!is_string($authorization)
            || preg_match('/^Bearer ([A-Za-z0-9._~-]+)$/D', $authorization, $matches) !== 1
        ) {
            throw new AccessDeniedHttpException('A bearer token is required.');
        }
        try {
            $response = $this->http->request('GET', rtrim($this->orderServiceUrl, '/') . '/api/v1/orders/' . $orderId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $matches[1],
                    'X-Correlation-ID' => (string) $request->headers->get('X-Correlation-ID', ''),
                ],
                'timeout' => 3,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new AccessDeniedHttpException('Order read permission is required.');
            }
            if ($status === 404) {
                return $this->problem(404, 'Order not found.');
            }
            if ($status !== 200) {
                return $this->problem(503, 'Order Service is temporarily unavailable.');
            }
            $order = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($order) || array_is_list($order)) {
                return $this->problem(502, 'Order Service returned an invalid response.');
            }
            if (($order['tenantId'] ?? null) !== $tenant || ($order['orderId'] ?? null) !== $orderId) {
                return $this->problem(404, 'Order not found in this tenant.');
            }
            if (!in_array($order['status'] ?? null, ['accepted', 'contract_pending'], true)) {
                return $this->problem(409, 'Only accepted or contract-pending orders can have a contract.');
            }
            return $order;
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return $this->problem(503, 'Order Service is temporarily unavailable.');
        }
    }

    /** @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function legalSnapshot(array $order): array
    {
        $customer = $order['customerSnapshot'] ?? null;
        $offer = $order['offerSnapshot'] ?? null;
        $quote = $order['quoteSnapshot'] ?? null;
        $vehicle = $order['vehicleSnapshot'] ?? null;
        $customerId = $order['customerId'] ?? null;
        if (!is_string($customerId) || Uuid::isValid($customerId) === false
            || !is_array($customer) || $customer === [] || array_is_list($customer)
            || !is_array($offer) || $offer === [] || array_is_list($offer)
            || !is_array($quote) || $quote === [] || array_is_list($quote)
            || !is_array($vehicle) || $vehicle === [] || array_is_list($vehicle)
            || ($customer['customerId'] ?? null) !== $customerId) {
            throw new \DomainException('The accepted order does not contain the required immutable legal snapshots.');
        }
        $snapshot = [
            'orderId' => $order['orderId'],
            'orderStatusAtCapture' => $order['status'],
            'customerId' => $customerId,
            'customerVersion' => $order['customerVersion'] ?? null,
            'customer' => $customer,
            'offer' => $offer,
            'quote' => $quote,
            'vehicle' => $vehicle,
            'capturedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) > 131072) {
            throw new \DomainException('Contract legal snapshot exceeds 128 KiB.');
        }
        return $snapshot;
    }

    /** @return array<string,mixed>|null */
    private function find(Connection $db, string $tenant, string $id): ?array
    {
        $row = $db->fetchAssociative(
            'SELECT contract_id, order_id, customer_id, contract_type, contract_version, status,
                    legal_snapshot, document_checksum, signature_provider, provider_envelope_id,
                    created_by, created_at, updated_at, prepared_at, signed_at, cancelled_at
             FROM contract_records WHERE tenant_id = :tenant AND contract_id = :id',
            ['tenant' => $tenant, 'id' => $id],
        );
        if (!is_array($row)) {
            return null;
        }
        $row['legalSnapshot'] = $this->decode($row['legal_snapshot']);
        unset($row['legal_snapshot']);
        return [
            'contractId' => $row['contract_id'],
            'orderId' => $row['order_id'],
            'customerId' => $row['customer_id'],
            'contractType' => $row['contract_type'],
            'contractVersion' => (int) $row['contract_version'],
            'status' => $row['status'],
            'legalSnapshot' => $row['legalSnapshot'],
            'documentChecksum' => $row['document_checksum'],
            'signatureProvider' => $row['signature_provider'],
            'providerEnvelopeId' => $row['provider_envelope_id'],
            'createdBy' => $row['created_by'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'preparedAt' => $row['prepared_at'],
            'signedAt' => $row['signed_at'],
            'cancelledAt' => $row['cancelled_at'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function audit(
        Connection $db,
        string $tenant,
        string $id,
        string $actor,
        string $event,
        array $payload,
    ): void
    {
        $db->insert('contract_audit_events', [
            'tenant_id' => $tenant,
            'event_id' => Uuid::v7()->toRfc4122(),
            'contract_id' => $id,
            'actor_id' => $actor,
            'event_type' => $event,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function outbox(Connection $db, string $tenant, string $id, string $type, array $payload): void
    {
        $eventId = Uuid::v7()->toRfc4122();
        $occurredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $envelope = [
            'eventId' => $eventId,
            'eventType' => $type,
            'schemaVersion' => 1,
            'producer' => 'contract-service',
            'aggregate' => ['type' => 'Contract', 'id' => $id],
            'tenantId' => $tenant,
            'occurredAt' => $occurredAt,
            'data' => $payload,
        ];
        $db->insert('contract_outbox', [
            'tenant_id' => $tenant,
            'event_id' => $eventId,
            'contract_id' => $id,
            'event_type' => $type,
            'payload' => json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function setTenant(Connection $db, string $tenant): void
    {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
    }

    private function principal(Request $request, string $scope): AuthenticatedPrincipal
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal || !$principal->hasScope($scope)) {
            throw new AccessDeniedHttpException('Required Contract permission is missing.');
        }
        return $principal;
    }

    private function tenant(AuthenticatedPrincipal $principal): string
    {
        if (!is_string($principal->tenantId) || $principal->tenantId === '') {
            throw new AccessDeniedHttpException('Tenant context is missing.');
        }
        return $principal->tenantId;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) {
            throw new \InvalidArgumentException('A JSON object is required.');
        }
        return $body;
    }

    private function uuid(mixed $value, string $field): string
    {
        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new \InvalidArgumentException($field . ' must be a UUID.');
        }
        return strtolower($value);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \UnexpectedValueException('Stored JSON object is invalid.');
        }
        return $decoded;
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        $title = match ($status) {
            400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict',
            422 => 'Unprocessable Entity', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
            default => 'Internal Server Error',
        };
        return new JsonResponse([
            'type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
