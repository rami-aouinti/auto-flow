<?php

declare(strict_types=1);

namespace App\Application\Port;

interface PaymentRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findByTenantAndId(string $tenantId, string $paymentId): ?array;
}
