<?php

declare(strict_types=1);

namespace App\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class FulfillmentEventHandler
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Returns false for an already committed event delivery.
     * The caller must ACK RabbitMQ only after this method returns.
     */
    public function handle(IntegrationEvent $event): bool
    {
        $this->db->beginTransaction();

        try {
            $this->db->executeQuery(
                "SELECT set_config('app.tenant_id', :tenant, true)",
                ['tenant' => $event->tenantId],
            );

            $inserted = $this->db->fetchOne(
                <<<'SQL'
                    INSERT INTO fulfillment_event_inbox
                        (tenant_id, event_id, event_type, order_id, payload_hash)
                    VALUES (:tenant, :event, :type, :order, :hash)
                    ON CONFLICT (tenant_id, event_id) DO NOTHING
                    RETURNING event_id
                    SQL,
                [
                    'tenant' => $event->tenantId,
                    'event' => $event->eventId,
                    'type' => $event->eventType,
                    'order' => $event->orderId,
                    'hash' => $event->payloadHash,
                ],
            );

            if ($inserted === false) {
                $existingHash = $this->db->fetchOne(
                    'SELECT payload_hash FROM fulfillment_event_inbox WHERE tenant_id=:tenant AND event_id=:event',
                    ['tenant' => $event->tenantId, 'event' => $event->eventId],
                );

                if (!is_string($existingHash) || !hash_equals(trim($existingHash), $event->payloadHash)) {
                    throw new \UnexpectedValueException('An eventId was reused with a different payload.');
                }

                $this->db->commit();

                return false;
            }

            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO fulfillment_records (tenant_id, fulfillment_id, order_id)
                    VALUES (:tenant, :fulfillment, :order)
                    ON CONFLICT (tenant_id, order_id) DO NOTHING
                    SQL,
                [
                    'tenant' => $event->tenantId,
                    'fulfillment' => Uuid::v7()->toRfc4122(),
                    'order' => $event->orderId,
                ],
            );

            $record = $this->db->fetchAssociative(
                'SELECT fulfillment_id, status, payment_captured_at, contract_signed_at
                 FROM fulfillment_records
                 WHERE tenant_id=:tenant AND order_id=:order
                 FOR UPDATE',
                ['tenant' => $event->tenantId, 'order' => $event->orderId],
            );

            if ($record === false) {
                throw new \RuntimeException('Fulfillment record could not be created or loaded.');
            }

            // Late prerequisite events must not reopen a cancelled or delivered aggregate.
            if (in_array((string) $record['status'], ['cancelled', 'delivered'], true)) {
                $this->markInboxProcessed($event);
                $this->db->commit();

                return true;
            }

            $column = $event->eventType === IntegrationEvent::PAYMENT_CAPTURED
                ? 'payment_captured_at'
                : 'contract_signed_at';
            $alreadyObserved = $record[$column] !== null;
            $previousStatus = (string) $record['status'];

            if (!$alreadyObserved) {
                $this->db->executeStatement(
                    sprintf(
                        'UPDATE fulfillment_records
                         SET %s=:occurred, updated_at=CURRENT_TIMESTAMP, version=version+1
                         WHERE tenant_id=:tenant AND fulfillment_id=:id',
                        $column,
                    ),
                    [
                        'occurred' => $event->occurredAt->format('Y-m-d H:i:sP'),
                        'tenant' => $event->tenantId,
                        'id' => $record['fulfillment_id'],
                    ],
                );
            }

            $updated = $this->db->fetchAssociative(
                'SELECT fulfillment_id, status, payment_captured_at, contract_signed_at
                 FROM fulfillment_records
                 WHERE tenant_id=:tenant AND fulfillment_id=:id',
                ['tenant' => $event->tenantId, 'id' => $record['fulfillment_id']],
            );

            if ($updated === false) {
                throw new \RuntimeException('Fulfillment record disappeared during event processing.');
            }

            $newStatus = $previousStatus;
            if (
                $previousStatus === 'awaiting_prerequisites'
                && $updated['payment_captured_at'] !== null
                && $updated['contract_signed_at'] !== null
            ) {
                $newStatus = 'ready_for_preparation';
                $this->db->executeStatement(
                    'UPDATE fulfillment_records
                     SET status=:status, updated_at=CURRENT_TIMESTAMP, version=version+1
                     WHERE tenant_id=:tenant AND fulfillment_id=:id',
                    [
                        'status' => $newStatus,
                        'tenant' => $event->tenantId,
                        'id' => $record['fulfillment_id'],
                    ],
                );
            }

            if (!$alreadyObserved) {
                $this->db->insert('fulfillment_audit_events', [
                    'tenant_id' => $event->tenantId,
                    'event_id' => Uuid::v7()->toRfc4122(),
                    'fulfillment_id' => $record['fulfillment_id'],
                    'actor_id' => $event->producer,
                    'event_type' => $event->eventType . '.observed',
                    'payload' => json_encode([
                        'sourceEventId' => $event->eventId,
                        'orderId' => $event->orderId,
                        'occurredAt' => $event->occurredAt->format(DATE_ATOM),
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $event->occurredAt->format('Y-m-d H:i:sP'),
                ]);
            }

            if ($previousStatus !== $newStatus) {
                $outboxId = Uuid::v7()->toRfc4122();
                $outboxType = 'fulfillment.ready_for_preparation.v1';
                $occurredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
                $envelope = [
                    'eventId' => $outboxId,
                    'eventType' => $outboxType,
                    'schemaVersion' => 1,
                    'producer' => 'fulfillment-service',
                    'aggregate' => ['type' => 'Fulfillment', 'id' => $record['fulfillment_id']],
                    'tenantId' => $event->tenantId,
                    'occurredAt' => $occurredAt,
                    'data' => [
                        'fulfillmentId' => $record['fulfillment_id'],
                        'orderId' => $event->orderId,
                        'status' => $newStatus,
                    ],
                ];

                $this->db->insert('fulfillment_outbox', [
                    'tenant_id' => $event->tenantId,
                    'event_id' => $outboxId,
                    'fulfillment_id' => $record['fulfillment_id'],
                    'event_type' => $outboxType,
                    'payload' => json_encode($envelope, JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt,
                ]);
            }

            $this->markInboxProcessed($event);
            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    private function markInboxProcessed(IntegrationEvent $event): void
    {
        $this->db->executeStatement(
            'UPDATE fulfillment_event_inbox
             SET processed_at=CURRENT_TIMESTAMP
             WHERE tenant_id=:tenant AND event_id=:event',
            ['tenant' => $event->tenantId, 'event' => $event->eventId],
        );
    }
}
