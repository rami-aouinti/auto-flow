<?php

declare(strict_types=1);

namespace App\Application\Port\Out;

use App\Domain\Model\PricingPolicy;

interface PricingPolicyRepositoryInterface
{
    public function save(PricingPolicy $policy): void;

    public function findActive(string $tenantId): ?PricingPolicy;

    public function findLatest(string $tenantId): ?PricingPolicy;

    public function findByVersion(string $tenantId, int $version): ?PricingPolicy;
}
