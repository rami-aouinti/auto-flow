<?php

declare(strict_types=1);

namespace App\Application\ExpireOffers;

use App\Domain\Repository\OfferRepositoryInterface;
use DateTimeImmutable;

final readonly class ExpireOffersHandler
{
    public function __construct(private OfferRepositoryInterface $offerRepository)
    {
    }

    public function __invoke(DateTimeImmutable $now, int $tenantBatchSize = 100, int $offersPerTenant = 100): int
    {
        if ($tenantBatchSize < 1 || $offersPerTenant < 1) {
            throw new \InvalidArgumentException('Expiration batch sizes must be positive.');
        }

        $expiredCount = 0;
        foreach ($this->offerRepository->findExpiredTenantIds($now, $tenantBatchSize) as $tenantId) {
            $expiredCount += $this->offerRepository->expireDueForTenant($tenantId, $now, $offersPerTenant);
        }

        return $expiredCount;
    }
}
