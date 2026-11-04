<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\Offer\Application;

use App\Application\RejectOffer\RejectOfferHandler;
use App\Domain\Entity\Offer;
use App\Domain\Exception\OfferNotFound;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\PricingSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RejectOfferHandlerTest extends TestCase
{
    public function testRejectsPublishedOfferAndPersistsIt(): void
    {
        $offer = Offer::create(
            OfferId::fromString('offer-123'),
            'tenant-1',
            'user-1',
            new PricingSnapshot('quote-123', 'vehicle-456', '329.00', '15792.00', '20792.00', '8000.00', 'EUR', 48, 425, 'pricing-v1'),
            new DateTimeImmutable('2026-11-04T10:00:00+00:00'),
            new DateTimeImmutable('2026-12-04T10:00:00+00:00'),
        );
        $offer->publish(new DateTimeImmutable('2026-11-05T10:00:00+00:00'));

        $repository = new class ($offer) implements OfferRepositoryInterface {
            public int $saveCount = 0;
            public function __construct(private Offer $offer) {}
            public function save(Offer $offer): void { $this->offer = $offer; ++$this->saveCount; }
            public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer
            {
                return $this->offer->createdBy() === $ownerId ? $this->offer : null;
            }
            public function findExpiredTenantIds(DateTimeImmutable $now, int $limit): array { return []; }
            public function expireDueForTenant(string $tenantId, DateTimeImmutable $now, int $limit): int { return 0; }
        };

        $rejected = (new RejectOfferHandler($repository))(
            OfferId::fromString('offer-123'),
            'tenant-1',
            'user-1',
            new DateTimeImmutable('2026-11-06T10:00:00+00:00'),
        );

        self::assertSame('rejected', $rejected->status()->value);
        self::assertSame(1, $repository->saveCount);
    }

    public function testCannotRejectOfferNotOwnedByCaller(): void
    {
        $repository = new class implements OfferRepositoryInterface {
            public function save(Offer $offer): void {}
            public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer { return null; }
            public function findExpiredTenantIds(DateTimeImmutable $now, int $limit): array { return []; }
            public function expireDueForTenant(string $tenantId, DateTimeImmutable $now, int $limit): int { return 0; }
        };

        $this->expectException(OfferNotFound::class);
        (new RejectOfferHandler($repository))(
            OfferId::fromString('offer-123'),
            'tenant-1',
            'other-user',
            new DateTimeImmutable('2026-11-06T10:00:00+00:00'),
        );
    }
}
