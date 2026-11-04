<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\Offer\Application;

use App\Application\ExpireOffers\ExpireOffersHandler;
use App\Domain\Entity\Offer;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ExpireOffersHandlerTest extends TestCase
{
    public function testExpiresDueOffersForEveryReturnedTenant(): void
    {
        $repository = new class () implements OfferRepositoryInterface {
            /** @var list<string> */
            public array $queriedTenants = [];

            public function save(Offer $offer): void
            {
            }

            public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer
            {
                return null;
            }

            public function findExpiredTenantIds(DateTimeImmutable $now, int $limit): array
            {
                return ['tenant-a', 'tenant-b'];
            }

            public function expireDueForTenant(string $tenantId, DateTimeImmutable $now, int $limit): int
            {
                $this->queriedTenants[] = $tenantId;

                return $tenantId === 'tenant-a' ? 2 : 1;
            }
        };

        $expiredCount = (new ExpireOffersHandler($repository))(
            new DateTimeImmutable('2026-11-12T00:00:00+00:00'),
        );

        self::assertSame(3, $expiredCount);
        self::assertSame(['tenant-a', 'tenant-b'], $repository->queriedTenants);
    }

    public function testRejectsInvalidBatchSizes(): void
    {
        $repository = new class implements OfferRepositoryInterface {
            public function save(Offer $offer): void
            {
            }
            public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer
            {
                return null;
            }
            public function findExpiredTenantIds(DateTimeImmutable $now, int $limit): array
            {
                return [];
            }
            public function expireDueForTenant(string $tenantId, DateTimeImmutable $now, int $limit): int
            {
                return 0;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        (new ExpireOffersHandler($repository))(new DateTimeImmutable(), 0, 100);
    }
}
