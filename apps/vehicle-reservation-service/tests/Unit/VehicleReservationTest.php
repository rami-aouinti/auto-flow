<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use AutoFlow\VehicleReservation\Domain\ReservationStatus;
use AutoFlow\VehicleReservation\Domain\VehicleReservation;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class VehicleReservationTest extends TestCase
{
    public function testHeldReservationCanBeConfirmedBeforeExpiry(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $reservation = $this->reservation($now->modify('+1 hour'));

        $reservation->confirm($now);

        self::assertSame(ReservationStatus::CONFIRMED, $reservation->status);
    }

    public function testExpiredHeldReservationCanBeExpiredOnlyAfterDeadline(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $reservation = $this->reservation($now->modify('+1 hour'));

        self::assertFalse($reservation->expire($now));
        self::assertTrue($reservation->expire($now->modify('+2 hours')));
        self::assertSame(ReservationStatus::EXPIRED, $reservation->status);
    }

    public function testConfirmedReservationCanBeFulfilled(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $reservation = $this->reservation($now->modify('+1 hour'));
        $reservation->confirm($now);
        $reservation->markFulfilled($now->modify('+1 minute'));

        self::assertSame(ReservationStatus::FULFILLED, $reservation->status);
    }

    public function testHeldReservationCannotBeFulfilledDirectly(): void
    {
        $reservation = $this->reservation(new DateTimeImmutable('+1 hour'));

        $this->expectException(DomainException::class);
        $reservation->markFulfilled(new DateTimeImmutable('now'));
    }

    public function testTerminalReservationCannotBeReleasedAgain(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $reservation = $this->reservation($now->modify('+1 hour'));
        $reservation->expire($now->modify('+2 hours'));

        $this->expectException(DomainException::class);
        $reservation->release($now->modify('+2 hours'));
    }

    private function reservation(DateTimeImmutable $expiresAt): VehicleReservation
    {
        return new VehicleReservation(
            'reservation-1',
            'tenant-1',
            'unit-1',
            'variant-1',
            'order-1',
            $expiresAt,
        );
    }
}
