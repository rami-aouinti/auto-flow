<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\Offer\Application;

use App\Application\CreateOffer\CreateOfferCommand;
use App\Application\CreateOffer\CreateOfferHandler;
use App\Application\DTO\PricingQuoteRequest;
use App\Application\Port\Out\PricingGatewayInterface;
use App\Domain\Entity\Offer;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\OfferStatus;
use App\Domain\ValueObject\PricingSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CreateOfferHandlerTest extends TestCase
{
    public function testCreatesOfferFromPricingSnapshotAndPersistsIt(): void
    {
        $snapshot = new PricingSnapshot(
            quoteId: 'quote-123',
            vehicleId: 'vehicle-456',
            monthlyAmount: '329.00',
            totalInstallmentsAmount: '15792.00',
            totalCostIncludingDownPayment: '20792.00',
            residualValue: '8000.00',
            currency: 'EUR',
            durationMonths: 48,
            annualInterestRateBasisPoints: 425,
            calculationVersion: 'pricing-v1',
        );

        $gateway = new class ($snapshot) implements PricingGatewayInterface {
            public ?PricingQuoteRequest $request = null;
            public ?string $subjectToken = null;

            public function __construct(private readonly PricingSnapshot $snapshot)
            {
            }

            public function createQuote(PricingQuoteRequest $request, string $subjectAccessToken): PricingSnapshot
            {
                $this->request = $request;
                $this->subjectToken = $subjectAccessToken;

                return $this->snapshot;
            }
        };

        $repository = new class () implements OfferRepositoryInterface {
            public ?Offer $savedOffer = null;

            public function save(Offer $offer): void
            {
                $this->savedOffer = $offer;
            }

            public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer
            {
                return null;
            }

            public function findByOwner(
                string $tenantId,
                string $ownerId,
                ?OfferStatus $status,
                ?string $search,
                int $limit,
                int $offset,
            ): array {
                return ['items' => [], 'total' => 0];
            }

            public function findHistoryByOffer(OfferId $id, string $tenantId, string $ownerId): ?array
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

        $handler = new CreateOfferHandler($gateway, $repository);
        $createdAt = new DateTimeImmutable('2026-11-04T10:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-12-04T10:00:00+00:00');

        $offer = $handler(new CreateOfferCommand(
            tenantId: 'tenant-1',
            createdBy: 'user-1',
            subjectAccessToken: 'header.payload.signature',
            vehicleId: 'vehicle-456',
            vehiclePrice: '25000.00',
            downPayment: '5000.00',
            residualValue: '8000.00',
            currency: 'EUR',
            durationMonths: 48,
            annualInterestRateBasisPoints: 425,
            createdAt: $createdAt,
            expiresAt: $expiresAt,
        ));

        self::assertSame($offer, $repository->savedOffer);
        self::assertSame('tenant-1', $offer->tenantId());
        self::assertSame('user-1', $offer->createdBy());
        self::assertSame('quote-123', $offer->pricingSnapshot()->quoteId);
        self::assertSame('header.payload.signature', $gateway->subjectToken);
        self::assertSame('vehicle-456', $gateway->request?->vehicleId);
    }
}
