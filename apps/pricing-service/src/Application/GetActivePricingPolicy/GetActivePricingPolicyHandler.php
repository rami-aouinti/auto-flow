<?php

declare(strict_types=1);

namespace App\Application\GetActivePricingPolicy;

use App\Application\Port\Out\PricingPolicyRepositoryInterface;
use App\Domain\Model\PricingPolicy;

final readonly class GetActivePricingPolicyHandler
{
    public function __construct(private PricingPolicyRepositoryInterface $repository)
    {
    }

    public function __invoke(string $tenantId): ?PricingPolicy
    {
        return $this->repository->findActive($tenantId);
    }
}
