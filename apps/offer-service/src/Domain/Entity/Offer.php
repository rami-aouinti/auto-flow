<?php

declare(strict_types=1);

namespace App\Domain\Entity;

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

    private function __construct(
        private readonly OfferId $id,
        private readonly string $tenantId,
        private readonly string $createdBy,
        private readonly PricingSnapshot $pricingSnapshot,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
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

        return new self($id, $tenantId, $createdBy, $pricingSnapshot, $createdAt, $expiresAt);
    }

    public static function reconstitute(
        OfferId $id,
        string $tenantId,
        string $createdBy,
        PricingSnapshot $pricingSnapshot,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        OfferStatus $status,
    ): self {
        $offer = self::create($id, $tenantId, $createdBy, $pricingSnapshot, $createdAt, $expiresAt);
        $offer->status = $status;

        return $offer;
    }

    public function publish(DateTimeImmutable $publishedAt): void
    {
        $this->assertStatus(OfferStatus::Draft);

        if ($publishedAt >= $this->expiresAt) {
            throw new InvalidOfferTransition('An expired offer cannot be published.');
        }

        $this->status = OfferStatus::Published;
    }

    public function accept(DateTimeImmutable $acceptedAt): void
    {
        $this->assertStatus(OfferStatus::Published);

        if ($acceptedAt >= $this->expiresAt) {
            throw new InvalidOfferTransition('An expired offer cannot be accepted.');
        }

        $this->status = OfferStatus::Accepted;
    }

    public function reject(): void
    {
        $this->assertStatus(OfferStatus::Published);
        $this->status = OfferStatus::Rejected;
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
    }

    public function withdraw(): void
    {
        if (!in_array($this->status, [OfferStatus::Draft, OfferStatus::Published], true)) {
            throw new InvalidOfferTransition('Only draft or published offers can be withdrawn.');
        }

        $this->status = OfferStatus::Withdrawn;
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
