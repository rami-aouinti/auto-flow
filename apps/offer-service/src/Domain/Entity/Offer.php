<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Event\DomainEvent;
use App\Domain\Event\OfferCreated;
use App\Domain\Event\OfferExpired;
use App\Domain\Event\OfferPublished;
use App\Domain\Exception\InvalidOfferTransition;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\OfferStatus;
use App\Domain\ValueObject\PricingSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Offer aggregate root.
 *
 * This is a pure domain entity: it has no Symfony or Doctrine dependency.
 */
final class Offer
{
    private OfferStatus $status;

    private ?OfferStatus $persistedStatus = null;

    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly OfferId $id,
        private readonly string $tenantId,
        private readonly string $createdBy,
        private readonly PricingSnapshot $pricingSnapshot,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $publishedAt = null,
        private ?DateTimeImmutable $acceptedAt = null,
        private ?DateTimeImmutable $rejectedAt = null,
        private ?DateTimeImmutable $expiredAt = null,
    ) {
        $this->status = OfferStatus::Draft;
    }

    public static function create(
        OfferId $id,
        string $tenantId,
        string $createdBy,
        PricingSnapshot $pricingSnapshot,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): self {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('Tenant ID must not be empty.');
        }

        if (trim($createdBy) === '') {
            throw new InvalidArgumentException('Creator ID must not be empty.');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('Offer expiration must be later than its creation time.');
        }

        $offer = new self($id, $tenantId, $createdBy, $pricingSnapshot, $createdAt, $expiresAt);
        $offer->record(new OfferCreated(
            eventId: (string) OfferId::generate(),
            tenantId: $tenantId,
            offerId: (string) $id,
            createdBy: $createdBy,
            quoteId: $pricingSnapshot->quoteId,
            vehicleId: $pricingSnapshot->vehicleId,
            occurredAt: $createdAt,
            expiresAt: $expiresAt,
        ));

        return $offer;
    }

    public static function reconstitute(
        OfferId $id,
        string $tenantId,
        string $createdBy,
        PricingSnapshot $pricingSnapshot,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        OfferStatus $status,
        ?DateTimeImmutable $publishedAt = null,
        ?DateTimeImmutable $acceptedAt = null,
        ?DateTimeImmutable $rejectedAt = null,
        ?DateTimeImmutable $expiredAt = null,
    ): self {
        $offer = self::create($id, $tenantId, $createdBy, $pricingSnapshot, $createdAt, $expiresAt);
        $offer->status = $status;
        $offer->publishedAt = $publishedAt;
        $offer->acceptedAt = $acceptedAt;
        $offer->rejectedAt = $rejectedAt;
        $offer->expiredAt = $expiredAt;
        $offer->persistedStatus = $status;
        $offer->clearDomainEvents();

        return $offer;
    }

    public function publish(DateTimeImmutable $publishedAt): void
    {
        $this->assertStatus(OfferStatus::Draft);

        if ($publishedAt < $this->createdAt) {
            throw new InvalidOfferTransition('An offer cannot be published before it was created.');
        }

        if ($publishedAt >= $this->expiresAt) {
            throw new InvalidOfferTransition('An expired offer cannot be published.');
        }

        $this->status = OfferStatus::Published;
        $this->publishedAt = $publishedAt;
        $this->record(new OfferPublished(
            eventId: (string) OfferId::generate(),
            tenantId: $this->tenantId,
            offerId: (string) $this->id,
            createdBy: $this->createdBy,
            quoteId: $this->pricingSnapshot->quoteId,
            vehicleId: $this->pricingSnapshot->vehicleId,
            occurredAt: $publishedAt,
            expiresAt: $this->expiresAt,
        ));
    }

    public function accept(DateTimeImmutable $acceptedAt): void
    {
        $this->assertStatus(OfferStatus::Published);

        $acceptanceWindowStart = $this->publishedAt ?? $this->createdAt;
        if ($acceptedAt < $acceptanceWindowStart) {
            throw new InvalidOfferTransition('An offer cannot be accepted before it was published.');
        }

        if ($acceptedAt >= $this->expiresAt) {
            throw new InvalidOfferTransition('An expired offer cannot be accepted.');
        }

        $this->status = OfferStatus::Accepted;
        $this->acceptedAt = $acceptedAt;
    }

    public function reject(DateTimeImmutable $rejectedAt): void
    {
        $this->assertStatus(OfferStatus::Published);

        $rejectionWindowStart = $this->publishedAt ?? $this->createdAt;
        if ($rejectedAt < $rejectionWindowStart || $rejectedAt >= $this->expiresAt) {
            throw new InvalidOfferTransition('An offer can only be rejected during its validity period.');
        }

        $this->status = OfferStatus::Rejected;
        $this->rejectedAt = $rejectedAt;
    }

    public function expire(DateTimeImmutable $expiredAt): void
    {
        if (!in_array($this->status, [OfferStatus::Draft, OfferStatus::Published], true)) {
            throw new InvalidOfferTransition('Only draft or published offers can expire.');
        }

        if ($expiredAt < $this->expiresAt) {
            throw new InvalidOfferTransition('An offer cannot expire before its expiration time.');
        }

        $this->status = OfferStatus::Expired;
        $this->expiredAt = $expiredAt;
        $this->record(new OfferExpired(
            eventId: (string) OfferId::generate(),
            tenantId: $this->tenantId,
            offerId: (string) $this->id,
            createdBy: $this->createdBy,
            quoteId: $this->pricingSnapshot->quoteId,
            vehicleId: $this->pricingSnapshot->vehicleId,
            occurredAt: $expiredAt,
            expiresAt: $this->expiresAt,
        ));
    }

    public function withdraw(): void
    {
        if (!in_array($this->status, [OfferStatus::Draft, OfferStatus::Published], true)) {
            throw new InvalidOfferTransition('Only draft or published offers can be withdrawn.');
        }

        $this->status = OfferStatus::Withdrawn;
    }

    /** @return list<DomainEvent> */
    public function domainEvents(): array
    {
        return $this->domainEvents;
    }

    public function persistedStatus(): ?OfferStatus
    {
        return $this->persistedStatus;
    }

    public function markPersisted(): void
    {
        $this->persistedStatus = $this->status;
        $this->clearDomainEvents();
    }

    public function clearDomainEvents(): void
    {
        $this->domainEvents = [];
    }

    private function record(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    public function id(): OfferId
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function pricingSnapshot(): PricingSnapshot
    {
        return $this->pricingSnapshot;
    }

    public function status(): OfferStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function acceptedAt(): ?DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function rejectedAt(): ?DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function expiredAt(): ?DateTimeImmutable
    {
        return $this->expiredAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    private function assertStatus(OfferStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new InvalidOfferTransition(sprintf(
                'Cannot perform this operation while offer status is "%s".',
                $this->status->value,
            ));
        }
    }
}
