<?php

declare(strict_types=1);

namespace App\Application\TransitionPayment;

use App\Application\PaymentNotFoundException;
use App\Application\Port\PaymentGatewayInterface;
use AutoFlow\CoreBundle\Application\Clock\ClockInterface;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Component\Uid\Uuid;

final readonly class TransitionPaymentHandler
{
    public function __construct(
        private ClockInterface $clock,
        private Connection $db,
        private PaymentGatewayInterface $gateway,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(string $tenant, string $actor, string $paymentId, string $key, string $operation, ?int $refundAmount = null): array
    {
        $this->assertKey($key);
        $requestHash = hash('sha256', json_encode([$paymentId, $operation, $refundAmount], JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            $this->setTenant($tenant);
            $this->db->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(:scope))',
                ['scope' => $tenant . ':' . $actor . ':' . $key],
            );
            $existing = $this->db->fetchAssociative(
                'SELECT request_hash,response_json FROM payment_operation_idempotency WHERE tenant_id=:tenant AND actor=:actor AND idempotency_key=:key',
                ['tenant' => $tenant,'actor' => $actor,'key' => $key],
            );
            if ($existing !== false) {
                if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
                    throw new DomainException('Idempotency-Key was already used for a different payment operation.');
                }
                $response = json_decode((string)$existing['response_json'], true, 512, JSON_THROW_ON_ERROR);
                $this->db->commit();
                return $response;
            }
            $row = $this->db->fetchAssociative(
                'SELECT * FROM payments WHERE tenant_id=:tenant AND payment_id=:id FOR UPDATE',
                ['tenant' => $tenant,'id' => $paymentId],
            );
            if ($row === false) {
                throw new PaymentNotFoundException();
            }
            $providerId = (string) ($row['provider_payment_id'] ?? '');
            if ($providerId === '') {
                throw new DomainException('Payment has no provider intent.');
            }
            if ($operation === 'capture' && $row['status'] !== 'authorized') {
                throw new DomainException('Only authorized payments can be captured.');
            }
            if ($operation === 'cancel' && !in_array($row['status'], ['pending','requires_action','authorized'], true)) {
                throw new DomainException('This payment cannot be cancelled in its current state.');
            }
            if ($operation === 'refund') {
                $remaining = (int)$row['amount_minor'] - (int)$row['amount_refunded_minor'];
                if (!in_array($row['status'], ['captured','partially_refunded'], true)) {
                    throw new DomainException('Only captured payments can be refunded.');
                }
                if ($refundAmount === null || $refundAmount < 1 || $refundAmount > $remaining) {
                    throw new DomainException('Refund amount exceeds the remaining captured amount.');
                }
            }
            $result = match ($operation) {
                'capture' => $this->gateway->capture($providerId, hash('sha256', $tenant . ':' . $actor . ':' . $paymentId . ':capture:' . $key)),
                'cancel' => $this->gateway->cancel($providerId, hash('sha256', $tenant . ':' . $actor . ':' . $paymentId . ':cancel:' . $key)),
                'refund' => $this->gateway->refund($providerId, $refundAmount ?? 0, hash('sha256', $tenant . ':' . $actor . ':' . $paymentId . ':refund:' . $key)),
                default => throw new DomainException('Unsupported payment operation.'),
            };
            $newStatus = match ($operation) {
                'capture' => 'captured',
                'cancel' => 'cancelled',
                'refund' => ((int)$row['amount_refunded_minor'] + ($refundAmount ?? 0) >= (int)$row['amount_minor']) ? 'refunded' : 'partially_refunded',
            };
            if ($operation === 'refund') {
                $this->db->insert('payment_refunds', [
                    'tenant_id' => $tenant,'refund_id' => Uuid::v7()->toRfc4122(),'payment_id' => $paymentId,
                    'provider_refund_id' => $result['id'] ?? null,'amount_minor' => $refundAmount,'status' => 'succeeded',
                ]);
                $this->db->executeStatement(
                    'UPDATE payments SET amount_refunded_minor=amount_refunded_minor+:amount WHERE tenant_id=:tenant AND payment_id=:id',
                    ['amount' => $refundAmount,'tenant' => $tenant,'id' => $paymentId],
                );
            }
            $this->db->update('payments', ['status' => $newStatus,'updated_at' => $this->clock->now()->format(DATE_ATOM)], ['tenant_id' => $tenant,'payment_id' => $paymentId]);
            $payload = ['paymentId' => $paymentId,'orderId' => $row['order_id'],'status' => $newStatus,'operation' => $operation,'amountMinor' => (int)$row['amount_minor'],'currency' => (string)$row['currency'],'refundAmountMinor' => $operation === 'refund' ? $refundAmount : null,'amountRefundedMinor' => $operation === 'refund' ? (int)$row['amount_refunded_minor'] + (int)$refundAmount : (int)$row['amount_refunded_minor']];
            $eventType = match ($operation) {
                'capture' => 'PaymentCaptured',
                'refund' => $newStatus === 'refunded' ? 'PaymentRefunded' : 'PaymentPartiallyRefunded',
                'cancel' => 'PaymentCancelled',
            };
            $this->recordEvent($tenant, $paymentId, $eventType, $payload);
            $this->db->insert('payment_operation_idempotency', [
                'tenant_id' => $tenant,'actor' => $actor,'idempotency_key' => $key,'request_hash' => $requestHash,
                'payment_id' => $paymentId,'response_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
            $this->db->commit();
            return $payload;
        } catch (\Throwable $exception) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function setTenant(string $tenant): void
    {
        $this->db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
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
            'occurredAt' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'data' => $payload,
        ];
        $this->db->insert('payment_outbox', [
            'event_id' => $eventId,'tenant_id' => $tenant,'payment_id' => $paymentId,
            'event_type' => $type,'payload' => json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) !== 1) {
            throw new DomainException('A valid Idempotency-Key is required.');
        }
    }
}
