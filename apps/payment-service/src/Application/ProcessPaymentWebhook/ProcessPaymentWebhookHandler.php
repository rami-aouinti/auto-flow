<?php

declare(strict_types=1);

namespace App\Application\ProcessPaymentWebhook;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;
use DomainException;
use RuntimeException;

final readonly class ProcessPaymentWebhookHandler
{
    public function __construct(private Connection $db) {}

    /** @param array<string,mixed> $event */
    public function __invoke(array $event): void
    {
        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? null;
        $object = $event['data']['object'] ?? [];
        if (!is_string($eventId) || !is_string($type) || !is_array($object)) {
            throw new DomainException('Malformed Stripe event.');
        }
        $metadata = $object['metadata'] ?? [];
        $tenantId = is_array($metadata) ? ($metadata['tenant_id'] ?? null) : null;
        $paymentId = is_array($metadata) ? ($metadata['payment_id'] ?? null) : null;
        $providerId = $object['id'] ?? ($object['payment_intent'] ?? null);
        if (!is_string($tenantId) || !is_string($paymentId) || !is_string($providerId)) {
            return;
        }
        $status = match ($type) {
            'payment_intent.amount_capturable_updated' => 'authorized',
            'payment_intent.succeeded' => 'captured',
            'payment_intent.payment_failed' => 'failed',
            'payment_intent.canceled' => 'cancelled',
            default => null,
        };
        if ($status === null) {
            return;
        }
        $this->db->beginTransaction();
        try {
            $this->db->insert('payment_webhook_events', [
                'provider_event_id'=>$eventId,'tenant_id'=>$tenantId,'event_type'=>$type,
            ]);
            $this->setTenant($tenantId);
            $row = $this->db->fetchAssociative(
                'SELECT tenant_id,payment_id,order_id,status FROM payments WHERE tenant_id=:tenant AND payment_id=:payment AND provider_payment_id=:provider FOR UPDATE',
                ['tenant'=>$tenantId,'payment'=>$paymentId,'provider'=>$providerId],
            );
            if ($row === false) {
                throw new RuntimeException('Payment intent is not persisted yet; Stripe webhook must be retried.');
            }
            $this->setTenant((string)$row['tenant_id']);
            $currentStatus = (string) $row['status'];
            $allowed = match ($currentStatus) {
                'pending', 'requires_action' => ['authorized', 'captured', 'failed', 'cancelled'],
                'authorized' => ['captured', 'failed', 'cancelled'],
                'failed' => ['authorized', 'captured', 'cancelled'],
                default => [],
            };
            if (in_array($status, $allowed, true)) {
                $this->db->update('payments', ['status'=>$status,'updated_at'=>date('c')], [
                    'tenant_id'=>$row['tenant_id'],'payment_id'=>$row['payment_id'],
                ]);
                $eventType = match ($status) {
                    'authorized' => 'PaymentAuthorized',
                    'captured' => 'PaymentCaptured',
                    'failed' => 'PaymentFailed',
                    'cancelled' => 'PaymentCancelled',
                };
                $this->recordEvent((string)$row['tenant_id'], (string)$row['payment_id'], $eventType, [
                    'paymentId'=>$row['payment_id'],'orderId'=>$row['order_id'],'status'=>$status,'providerEventId'=>$eventId,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            // Duplicate webhook event is an acknowledged no-op.
            if ($exception instanceof UniqueConstraintViolationException) {
                return;
            }
            throw $exception;
        }
    }

    private function setTenant(string $tenant): void
    {
        $this->db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant'=>$tenant]);
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(string $tenant, string $paymentId, string $type, array $payload): void
    {
        $eventId = Uuid::v7()->toRfc4122();
        $envelope = [
            'eventId' => $eventId,
            'eventType' => $type,
            'schemaVersion' => 1,
            'producer' => 'payment-service',
            'aggregate' => ['type' => 'Payment', 'id' => $paymentId],
            'tenantId' => $tenant,
            'occurredAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'data' => $payload,
        ];
        $this->db->insert('payment_outbox', [
            'event_id'=>$eventId,'tenant_id'=>$tenant,'payment_id'=>$paymentId,
            'event_type'=>$type,'payload'=>json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);
    }

}
