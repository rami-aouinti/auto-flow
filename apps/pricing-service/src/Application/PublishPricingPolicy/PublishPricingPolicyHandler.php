<?php

declare(strict_types=1);

namespace App\Application\PublishPricingPolicy;

use App\Application\Port\Out\PricingPolicyRepositoryInterface;
use App\Domain\Model\PricingPolicy;
use DateTimeImmutable;
use DomainException;

final readonly class PublishPricingPolicyHandler
{
    public function __construct(private PricingPolicyRepositoryInterface $repository)
    {
    }

    public function __invoke(string $tenantId, int $version): PricingPolicy
    {
        $policy = $this->repository->findByVersion($tenantId, $version);
        if ($policy === null) {
            throw new DomainException('Pricing policy version was not found.');
        }
        $latest = $this->repository->findLatest($tenantId);
        if ($latest === null || $latest->policyId !== $policy->policyId) {
            throw new DomainException('Only the latest tenant pricing policy version can be published.');
        }
        $active = $policy->activate(new DateTimeImmutable('now'));
        $this->repository->save($active);
        return $active;
    }
}
