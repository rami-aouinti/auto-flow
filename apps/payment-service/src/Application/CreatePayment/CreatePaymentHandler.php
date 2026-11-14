<?php

declare(strict_types=1);

namespace App\Application\CreatePayment;

use App\Application\Port\OrderPaymentContextProviderInterface;
use App\Application\Port\PaymentGatewayInterface;
use AutoFlow\CoreBundle\Application\Clock\ClockInterface;
use Doctrine\DBAL\Connection;
use DomainException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final readonly class CreatePaymentHandler
{
    public function __construct(
        private ClockInterface $clock,
        private Connection $db,
        private PaymentGatewayInterface $gateway,
        private OrderPaymentContextProviderInterface $orderContext,
    ) {
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed>
     */
    public function __invoke(string $tenant, string $actor, string $key, array $input): array
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
                ['tenant' => $tenant,'actor' => $actor,'key' => $key],
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
                ['tenant' => $tenant,'actor' => $actor,'key' => $key],
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
                $amount,
                $currency,
                $paymentId,
                $tenant,
                hash('sha256', $tenant . ':' . $actor . ':' . $key . ':create'),
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
                'tenant_id' => $tenant,'payment_id' => $paymentId,'order_id' => $orderId,'created_by' => $actor,
                'provider' => 'stripe','provider_payment_id' => $providerId,'amount_minor' => $amount,
                'currency' => $currency,'status' => $status,
                'billing_snapshot' => json_encode($orderContext['billingSnapshot'], JSON_THROW_ON_ERROR),
            ]);
            $response = [
                'paymentId' => $paymentId,'orderId' => $orderId,'provider' => 'stripe','providerPaymentId' => $providerId,
                'amountMinor' => $amount,'currency' => $currency,'status' => $status,
                'clientSecret' => $intent['client_secret'] ?? null,
            ];
            $this->db->insert('payment_idempotency', [
                'tenant_id' => $tenant,'actor' => $actor,'idempotency_key' => $key,'request_hash' => $hash,
                'payment_id' => $paymentId,'response_json' => json_encode($response, JSON_THROW_ON_ERROR),
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
