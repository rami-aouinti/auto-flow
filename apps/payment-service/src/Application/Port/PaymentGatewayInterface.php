<?php

declare(strict_types=1);

namespace App\Application\Port;

interface PaymentGatewayInterface
{
    /** @return array<string,mixed> */
    public function createIntent(int $amountMinor, string $currency, string $paymentId, string $tenantId, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function capture(string $providerPaymentId, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function cancel(string $providerPaymentId, string $idempotencyKey): array;

    /** @return array<string,mixed> */
    public function refund(string $providerPaymentId, int $amountMinor, string $idempotencyKey): array;
}
