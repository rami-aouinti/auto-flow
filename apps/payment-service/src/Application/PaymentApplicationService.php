<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\PaymentGatewayInterface;
use App\Application\Port\OrderPaymentContextProviderInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use DomainException;
use RuntimeException;

final readonly class PaymentApplicationService
{
    public function __construct(
        private Connection $db,
        private PaymentGatewayInterface $gateway,
        private OrderPaymentContextProviderInterface $orderContext,
    ) {
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function create(string $tenant, string $actor, string $key, array $input): array
    {
        $orderId = $this->requiredString($input, 'orderId');
        if (!Uuid::isValid($orderId)) {
            throw new DomainException('orderId must be a valid UUID.');
        }
        if (array_diff(array_keys($input), ['orderId']) !== []) {
            throw new DomainException('Only orderId is accepted; amount and currency are resolved from Order Service.');
        }
        $this->assertKey($key);
        // Idempotent retries must still return the original result after Order advances
        // from accepted to contract_pending.
        $hash = hash('sha256', json_encode([$orderId], JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            $this->setTenant($tenant);
            $existing = $this->db->fetchAssociative(
                'SELECT request_hash, response_json FROM payment_idempotency WHERE tenant_id=:tenant AND actor=:actor AND idempotency_key=:key',
                ['tenant'=>$tenant,'actor'=>$actor,'key'=>$key],
            );
            if ($existing !== false) {
                if (!hash_equals((string) $existing['request_hash'], $hash)) {
                    throw new DomainException('Idempotency-Key was already used for a different payment request.');
                }
                $response = json_decode((string) $existing['response_json'], true, 512, JSON_THROW_ON_ERROR);
                $this->db->commit();
                return $response;
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        // Order owns the immutable commercial snapshot and is the only source of payable amount.
        $orderContext = $this->orderContext->getPayableContext($tenant, $orderId);
        $amount = $orderContext['amountMinor'];
        $currency = strtoupper($orderContext['currency']);
        if ($amount < 50 || $amount > 99_999_999 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('The accepted order has an invalid payable amount or currency.');
        }

        $this->db->beginTransaction();
        try {
            $this->setTenant($tenant);
            $this->db->executeStatement(
                "SELECT pg_advisory_xact_lock(hashtext(:scope))",
                ['scope' => $tenant . ':' . $actor . ':' . $key],
            );
            $existing = $this->db->fetchAssociative(
                'SELECT request_hash, response_json FROM payment_idempotency WHERE tenant_id=:tenant AND actor=:actor AND idempotency_key=:key',
                ['tenant'=>$tenant,'actor'=>$actor,'key'=>$key],
            );
            if ($existing !== false) {
                if (!hash_equals((string) $existing['request_hash'], $hash)) {
                    throw new DomainException('Idempotency-Key was already used for a different payment request.');
                }
                $response = json_decode((string) $existing['response_json'], true, 512, JSON_THROW_ON_ERROR);
                $this->db->commit();
                return $response;
            }

            $paymentId = Uuid::v7()->toRfc4122();
            $intent = $this->gateway->createIntent(
                $amount, $currency, $paymentId, $tenant, hash('sha256', $tenant . ':' . $actor . ':' . $key . ':create'),
            );
            $providerId = $intent['id'] ?? null;
            if (!is_string($providerId) || $providerId === '') {
                throw new RuntimeException('Payment provider did not return a payment intent identifier.');
            }
            $status = match ($intent['status'] ?? '') {
                'requires_action', 'requires_confirmation' => 'requires_action',
                'requires_payment_method' => 'pending',
                'requires_capture' => 'authorized',
                'succeeded' => 'captured',
                default => 'pending',
            };
            $this->db->insert('payments', [
                'tenant_id'=>$tenant,'payment_id'=>$paymentId,'order_id'=>$orderId,'created_by'=>$actor,
                'provider'=>'stripe','provider_payment_id'=>$providerId,'amount_minor'=>$amount,
                'currency'=>$currency,'status'=>$status,
            ]);
            $response = [
                'paymentId'=>$paymentId,'orderId'=>$orderId,'provider'=>'stripe','providerPaymentId'=>$providerId,
                'amountMinor'=>$amount,'currency'=>$currency,'status'=>$status,
                'clientSecret'=>$intent['client_secret'] ?? null,
            ];
            $this->db->insert('payment_idempotency', [
                'tenant_id'=>$tenant,'actor'=>$actor,'idempotency_key'=>$key,'request_hash'=>$hash,
                'payment_id'=>$paymentId,'response_json'=>json_encode($response, JSON_THROW_ON_ERROR),
            ]);
            $eventPayload = $response;
            unset($eventPayload['clientSecret']);
            $this->recordEvent($tenant, $paymentId, 'PaymentCreated', $eventPayload);
            $this->db->commit();
            return $response;
        } catch (\Throwable $exception) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function get(string $tenant, string $paymentId): array
    {
        $this->db->beginTransaction();
        try {
            $this->setTenant($tenant);
            $row = $this->db->fetchAssociative(
                'SELECT payment_id,order_id,provider,provider_payment_id,amount_minor,currency,status,amount_refunded_minor,created_at,updated_at FROM payments WHERE tenant_id=:tenant AND payment_id=:id',
                ['tenant'=>$tenant,'id'=>$paymentId],
            );
            $this->db->commit();
            if ($row === false) {
                throw new PaymentNotFoundException();
            }
            return $row;
        } catch (\Throwable $exception) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function transition(string $tenant, string $actor, string $paymentId, string $key, string $operation, ?int $refundAmount = null): array
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
                ['tenant'=>$tenant,'actor'=>$actor,'key'=>$key],
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
                ['tenant'=>$tenant,'id'=>$paymentId],
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
                    'tenant_id'=>$tenant,'refund_id'=>Uuid::v7()->toRfc4122(),'payment_id'=>$paymentId,
                    'provider_refund_id'=>$result['id'] ?? null,'amount_minor'=>$refundAmount,'status'=>'succeeded',
                ]);
                $this->db->executeStatement(
                    'UPDATE payments SET amount_refunded_minor=amount_refunded_minor+:amount WHERE tenant_id=:tenant AND payment_id=:id',
                    ['amount'=>$refundAmount,'tenant'=>$tenant,'id'=>$paymentId],
                );
            }
            $this->db->update('payments', ['status'=>$newStatus,'updated_at'=>date('c')], ['tenant_id'=>$tenant,'payment_id'=>$paymentId]);
            $payload = ['paymentId'=>$paymentId,'orderId'=>$row['order_id'],'status'=>$newStatus,'operation'=>$operation];
            $eventType = match ($operation) {
                'capture' => 'PaymentCaptured',
                'refund' => $newStatus === 'refunded' ? 'PaymentRefunded' : 'PaymentPartiallyRefunded',
                'cancel' => 'PaymentCancelled',
            };
            $this->recordEvent($tenant, $paymentId, $eventType, $payload);
            $this->db->insert('payment_operation_idempotency', [
                'tenant_id'=>$tenant,'actor'=>$actor,'idempotency_key'=>$key,'request_hash'=>$requestHash,
                'payment_id'=>$paymentId,'response_json'=>json_encode($payload, JSON_THROW_ON_ERROR),
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

    /** @param array<string,mixed> $event */
    public function applyWebhook(array $event): void
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
                'SELECT tenant_id,payment_id,order_id FROM payments WHERE tenant_id=:tenant AND payment_id=:payment AND provider_payment_id=:provider',
                ['tenant'=>$tenantId,'payment'=>$paymentId,'provider'=>$providerId],
            );
            if ($row !== false) {
                $this->setTenant((string)$row['tenant_id']);
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
            if (str_contains(strtolower($exception->getMessage()), 'duplicate') || str_contains(strtolower($exception->getMessage()), 'unique')) {
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

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException($key . ' is required.');
        }
        return $value;
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) !== 1) {
            throw new DomainException('A valid Idempotency-Key is required.');
        }
    }
}
