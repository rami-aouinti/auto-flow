<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\Offer\Domain;

use App\Domain\Entity\Offer;
use App\Domain\Event\OfferCreated;
use App\Domain\Event\OfferExpired;
use App\Domain\Event\OfferPublished;
use App\Domain\Exception\InvalidOfferTransition;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\OfferStatus;
use App\Domain\ValueObject\PricingSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OfferTest extends TestCase
{
    public function testNewOfferStartsAsDraftAndKeepsPricingSnapshot(): void
    {
        $offer = $this->createOffer();

        self::assertSame(OfferStatus::Draft, $offer->status());
        self::assertSame('quote-123', $offer->pricingSnapshot()->quoteId);
        self::assertSame('vehicle-456', $offer->pricingSnapshot()->vehicleId);
        self::assertSame('tenant-1', $offer->tenantId());
    }

    public function testCreationRecordsOfferCreatedDomainEvent(): void
    {
        $offer = $this->createOffer();

        self::assertCount(1, $offer->domainEvents());
        self::assertInstanceOf(OfferCreated::class, $offer->domainEvents()[0]);
    }

    public function testPublishAndExpireRecordTheirDomainEvents(): void
    {
        $offer = $this->createOffer();
        $publishedAt = new DateTimeImmutable('2026-11-05T10:00:00+00:00');
        $offer->publish($publishedAt);

        self::assertInstanceOf(OfferPublished::class, $offer->domainEvents()[1]);
        self::assertEquals($publishedAt, $offer->publishedAt());

        $expiredAt = new DateTimeImmutable('2026-11-12T00:00:00+00:00');
        $offer->expire($expiredAt);

        self::assertCount(3, $offer->domainEvents());
        self::assertInstanceOf(OfferExpired::class, $offer->domainEvents()[2]);
        self::assertEquals($expiredAt, $offer->expiredAt());
    }

    public function testReconstitutedOfferDoesNotEmitCreationEvent(): void
    {
        $offer = $this->createOffer();
        $reconstituted = Offer::reconstitute(
            $offer->id(),
            $offer->tenantId(),
            $offer->createdBy(),
            $offer->pricingSnapshot(),
            $offer->createdAt(),
            $offer->expiresAt(),
            OfferStatus::Draft,
        );

        self::assertSame([], $reconstituted->domainEvents());
    }

    public function testPublishedOfferCanBeAccepted(): void
    {
        $offer = $this->createOffer();
        $offer->publish(new DateTimeImmutable('2026-11-04T12:00:00+00:00'));

        self::assertSame(OfferStatus::Published, $offer->status());

        $acceptedAt = new DateTimeImmutable('2026-11-05T12:00:00+00:00');
        $offer->accept($acceptedAt);

        self::assertSame(OfferStatus::Accepted, $offer->status());
        self::assertEquals($acceptedAt, $offer->acceptedAt());
    }

    public function testPublishedOfferCanBeRejectedAndRecordsTimestamp(): void
    {
        $offer = $this->createOffer();
        $offer->publish(new DateTimeImmutable('2026-11-04T12:00:00+00:00'));
        $rejectedAt = new DateTimeImmutable('2026-11-05T12:00:00+00:00');

        $offer->reject($rejectedAt);

        self::assertSame(OfferStatus::Rejected, $offer->status());
        self::assertEquals($rejectedAt, $offer->rejectedAt());
    }

    public function testDraftOfferCannotBeAccepted(): void
    {
        $offer = $this->createOffer();

        $this->expectException(InvalidOfferTransition::class);
        $offer->accept(new DateTimeImmutable('2026-11-05T12:00:00+00:00'));
    }

    public function testOfferCannotBePublishedAfterExpiration(): void
    {
        $offer = $this->createOffer();

        $this->expectException(InvalidOfferTransition::class);
        $offer->publish(new DateTimeImmutable('2026-12-01T00:00:00+00:00'));
    }

    public function testPublishedOfferCannotBeRejectedAfterExpiration(): void
    {
        $offer = $this->createOffer();
        $offer->publish(new DateTimeImmutable('2026-11-05T10:00:00+00:00'));

        $this->expectException(InvalidOfferTransition::class);
        $offer->reject(new DateTimeImmutable('2026-11-12T00:00:00+00:00'));
    }

    public function testOfferCanExpireOnlyAtOrAfterItsExpirationTime(): void
    {
        $offer = $this->createOffer();
        $this->expectException(InvalidOfferTransition::class);

        $offer->expire(new DateTimeImmutable('2026-11-10T23:59:59+00:00'));
    }

    public function testOfferCanBeMarkedExpiredAtExpirationTime(): void
    {
        $offer = $this->createOffer();
        $offer->expire(new DateTimeImmutable('2026-11-11T00:00:00+00:00'));

        self::assertSame(OfferStatus::Expired, $offer->status());
        self::assertEquals(new DateTimeImmutable('2026-11-11T00:00:00+00:00'), $offer->expiredAt());
    }

    public function testCreationRejectsExpirationBeforeCreation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Offer::create(
            OfferId::generate(),
            'tenant-1',
            'user-1',
            $this->pricingSnapshot(),
            new DateTimeImmutable('2026-11-12T00:00:00+00:00'),
            new DateTimeImmutable('2026-11-11T00:00:00+00:00'),
        );
    }

    private function createOffer(): Offer
    {
        return Offer::create(
            OfferId::fromString('offer-123'),
            'tenant-1',
            'user-1',
            $this->pricingSnapshot(),
            new DateTimeImmutable('2026-11-04T10:00:00+00:00'),
            new DateTimeImmutable('2026-11-11T00:00:00+00:00'),
        );
    }

    private function pricingSnapshot(): PricingSnapshot
    {
        return new PricingSnapshot(
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
    }
}
