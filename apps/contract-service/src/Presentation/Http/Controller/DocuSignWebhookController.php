<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use Doctrine\DBAL\Connection;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class DocuSignWebhookController
{
    public function __construct(
        private Connection $db,
        private string $hmacKey,
    ) {
    }

    #[Route('/webhooks/docusign', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if ($this->hmacKey === '') {
            return new JsonResponse(['error' => 'webhook_not_configured'], 503);
        }
        if (!$this->isAuthentic($raw, $request)) {
            return new JsonResponse(['error' => 'invalid_signature'], 401, [
                'Cache-Control' => 'no-store',
            ]);
        }

        try {
            $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($event) || array_is_list($event)) {
                return new JsonResponse(['error' => 'invalid_event'], 400);
            }
            $data = $event['data'] ?? null;
            $summary = is_array($data) ? ($data['envelopeSummary'] ?? []) : [];
            $envelopeId = is_array($data)
                ? ($data['envelopeId'] ?? (is_array($summary) ? ($summary['envelopeId'] ?? null) : null))
                : null;
            $eventType = $event['event'] ?? null;
            $status = is_array($summary) ? ($summary['status'] ?? null) : null;
            $generatedAt = $event['generatedDateTime'] ?? null;
            if (!is_string($envelopeId) || $envelopeId === ''
                || !is_string($eventType) || $eventType === '') {
                return new JsonResponse(['error' => 'invalid_event'], 400);
            }

            $tenantRow = $this->db->fetchAssociative(
                'SELECT tenant_id, contract_id
                 FROM public.contract_find_tenant_for_envelope(:envelope_id)',
                ['envelope_id' => $envelopeId],
            );
            // Unknown envelope IDs are acknowledged without revealing tenant data.
            if (!is_array($tenantRow)) {
                return new JsonResponse(['received' => true], 200);
            }

            $tenant = (string) $tenantRow['tenant_id'];
            $contractId = (string) $tenantRow['contract_id'];
            $eventIdentity = is_string($generatedAt) ? $generatedAt : hash('sha256', $raw);
            $eventKey = hash('sha256', $envelopeId . '|' . $eventType . '|' . $eventIdentity);
            $payloadHash = hash('sha256', $raw);
            $normalizedStatus = is_string($status) ? strtolower($status) : '';
            $normalizedEvent = strtolower($eventType);

            $this->db->transactional(function (Connection $db) use (
                $tenant,
                $contractId,
                $envelopeId,
                $eventKey,
                $payloadHash,
                $eventType,
                $normalizedStatus,
                $normalizedEvent,
            ): void {
                $db->executeQuery(
                    "SELECT set_config('app.tenant_id', :tenant, true)",
                    ['tenant' => $tenant],
                );
                $inserted = $db->fetchOne(
                    'INSERT INTO contract_signature_webhook_inbox
                        (tenant_id, event_key, provider, provider_envelope_id, event_type, payload_hash)
                     VALUES (:tenant, :event_key, :provider, :envelope, :event_type, :payload_hash)
                     ON CONFLICT (tenant_id, event_key) DO NOTHING
                     RETURNING event_key',
                    [
                        'tenant' => $tenant,
                        'event_key' => $eventKey,
                        'provider' => 'docusign',
                        'envelope' => $envelopeId,
                        'event_type' => mb_substr($eventType, 0, 100),
                        'payload_hash' => $payloadHash,
                    ],
                );
                if (!is_string($inserted)) {
                    return;
                }

                $contract = $db->fetchAssociative(
                    'SELECT order_id, status FROM contract_records
                     WHERE tenant_id = :tenant AND contract_id = :id
                       AND provider_envelope_id = :envelope
                     FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $contractId, 'envelope' => $envelopeId],
                );
                if (!is_array($contract) || $contract['status'] !== 'awaiting_signature') {
                    $db->update('contract_signature_webhook_inbox', [
                        'processed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ], ['tenant_id' => $tenant, 'event_key' => $eventKey]);

                    return;
                }

                $targetStatus = null;
                $auditType = null;
                $outboxType = null;
                if ($normalizedStatus === 'completed' || $normalizedEvent === 'envelope-completed') {
                    $targetStatus = 'signed';
                    $auditType = 'ContractSigned';
                    $outboxType = 'contract.signed.v1';
                } elseif (in_array($normalizedStatus, ['declined', 'voided'], true)
                    || in_array($normalizedEvent, ['envelope-declined', 'envelope-voided'], true)) {
                    $targetStatus = 'cancelled';
                    $auditType = $normalizedStatus === 'declined' ? 'ContractSignatureDeclined' : 'ContractSignatureVoided';
                    $outboxType = 'contract.signature.failed.v1';
                }

                if ($targetStatus !== null) {
                    $timestamp = (new \DateTimeImmutable())->format(DATE_ATOM);
                    $changes = [
                        'status' => $targetStatus,
                        'updated_at' => $timestamp,
                    ];
                    if ($targetStatus === 'signed') {
                        $changes['signed_at'] = $timestamp;
                    } else {
                        $changes['cancelled_at'] = $timestamp;
                    }
                    $db->update('contract_records', $changes, [
                        'tenant_id' => $tenant,
                        'contract_id' => $contractId,
                    ]);
                    $db->insert('contract_audit_events', [
                        'tenant_id' => $tenant,
                        'event_id' => Uuid::v7()->toRfc4122(),
                        'contract_id' => $contractId,
                        'actor_id' => 'provider:docusign',
                        'event_type' => $auditType,
                        'payload' => json_encode([
                            'provider' => 'docusign',
                            'envelopeId' => $envelopeId,
                            'providerEvent' => $eventType,
                            'status' => $normalizedStatus,
                        ], JSON_THROW_ON_ERROR),
                    ]);
                    $outboxEventId = Uuid::v7()->toRfc4122();
                    $outboxOccurredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
                    $outboxEnvelope = [
                        'eventId' => $outboxEventId,
                        'eventType' => $outboxType,
                        'schemaVersion' => 1,
                        'producer' => 'contract-service',
                        'aggregate' => ['type' => 'Contract', 'id' => $contractId],
                        'tenantId' => $tenant,
                        'occurredAt' => $outboxOccurredAt,
                        'data' => [
                            'contractId' => $contractId,
                            'orderId' => (string) $contract['order_id'],
                            'provider' => 'docusign',
                            'envelopeId' => $envelopeId,
                            'status' => $targetStatus,
                        ],
                    ];
                    $db->insert('contract_outbox', [
                        'tenant_id' => $tenant,
                        'event_id' => $outboxEventId,
                        'contract_id' => $contractId,
                        'event_type' => $outboxType,
                        'payload' => json_encode($outboxEnvelope, JSON_THROW_ON_ERROR),
                    ]);
                }

                $db->update('contract_signature_webhook_inbox', [
                    'processed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ], ['tenant_id' => $tenant, 'event_key' => $eventKey]);
            });

            return new JsonResponse(['received' => true], 200, ['Cache-Control' => 'no-store']);
        } catch (JsonException) {
            return new JsonResponse(['error' => 'invalid_json'], 400);
        } catch (Throwable) {
            // A 5xx response asks DocuSign Connect to retry; do not acknowledge
            // an event whose inbox and state transition were not committed.
            return new JsonResponse(['error' => 'webhook_processing_failed'], 503, [
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    private function isAuthentic(string $payload, Request $request): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $payload, $this->hmacKey, true));
        for ($index = 1; $index <= 5; ++$index) {
            $signature = $request->headers->get('X-DocuSign-Signature-' . $index);
            if (is_string($signature) && $signature !== '' && hash_equals($expected, trim($signature))) {
                return true;
            }
        }

        return false;
    }
}
