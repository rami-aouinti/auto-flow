<?php

declare(strict_types=1);

namespace AutoFlow\VehicleReservation\Domain;

use DomainException;

final class VehicleReservation
{
    public private(set) ReservationStatus $status;
    private ?\DateTimeImmutable $confirmedAt = null;
    private ?\DateTimeImmutable $releasedAt = null;
    private ?\DateTimeImmutable $fulfilledAt = null;

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $vehicleUnitId,
        public readonly string $vehicleVariantId,
        public readonly string $orderId,
        public readonly \DateTimeImmutable $expiresAt,
        ReservationStatus $status = ReservationStatus::HELD,
    ) {
        foreach ([
            'id' => $id,
            'tenantId' => $tenantId,
            'vehicleUnitId' => $vehicleUnitId,
            'vehicleVariantId' => $vehicleVariantId,
            'orderId' => $orderId,
        ] as $field => $value) {
            if (trim($value) === '' || strlen($value) > 128) {
                throw new DomainException(sprintf('%s must be a non-empty identifier of at most 128 characters.', $field));
            }
        }

        if ($expiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new DomainException('Reservation expiry must be in the future.');
        }

        $this->status = $status;
    }

    public function confirm(\DateTimeImmutable $now): void
    {
        $this->assertStatus(ReservationStatus::HELD, 'Only a held reservation can be confirmed.');

        if ($now >= $this->expiresAt) {
            throw new DomainException('An expired reservation cannot be confirmed.');
        }

        $this->confirmedAt = $now;
        $this->status = ReservationStatus::CONFIRMED;
    }

    public function expire(\DateTimeImmutable $now): bool
    {
        if ($this->status !== ReservationStatus::HELD || $now < $this->expiresAt) {
            return false;
        }

        $this->status = ReservationStatus::EXPIRED;

        return true;
    }

    public function release(\DateTimeImmutable $now): void
    {
        if (!$this->status->isActive()) {
            throw new DomainException(sprintf('A %s reservation cannot be released.', $this->status->value));
        }

        $this->releasedAt = $now;
        $this->status = ReservationStatus::RELEASED;
    }

    public function markFulfilled(\DateTimeImmutable $now): void
    {
        $this->assertStatus(ReservationStatus::CONFIRMED, 'Only a confirmed reservation can be fulfilled.');

        $this->fulfilledAt = $now;
        $this->status = ReservationStatus::FULFILLED;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->status === ReservationStatus::HELD && $now >= $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenantId' => $this->tenantId,
            'vehicleUnitId' => $this->vehicleUnitId,
            'vehicleVariantId' => $this->vehicleVariantId,
            'orderId' => $this->orderId,
            'status' => $this->status->value,
            'expiresAt' => $this->expiresAt->format(DATE_ATOM),
            'confirmedAt' => $this->confirmedAt?->format(DATE_ATOM),
            'releasedAt' => $this->releasedAt?->format(DATE_ATOM),
            'fulfilledAt' => $this->fulfilledAt?->format(DATE_ATOM),
        ];
    }

    private function assertStatus(ReservationStatus $expected, string $message): void
    {
        if ($this->status !== $expected) {
            throw new DomainException($message);
        }
    }
}
