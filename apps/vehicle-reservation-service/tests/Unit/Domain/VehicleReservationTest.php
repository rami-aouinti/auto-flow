<?php

declare(strict_types=1);

namespace AutoFlow\\VehicleReservation\\Tests\\Unit\\Domain;

use AutoFlow\\VehicleReservation\\Domain\\ReservationStatus;
use AutoFlow\\VehicleReservation\\Domain\\VehicleReservation;
use DomainException;
use PHPUnit\\Framework\\TestCase;

final class VehicleReservationTest extends TestCase
{
    public function testReservationCanBeConfirmedBeforeExpiry(): void
    {
        $reservation = $this->newReservation();
        $reservation->confirm($this->at('+30 minutes'));

        self::assertSame(ReservationStatus::CONFIRMED, $reservation->status);
        self::assertSame($this->at('+30 minutes')->format(DATE_ATOM), $reservation->toArray()['confirmedAt']);
    }

    public function testReservationCannotBeConfirmedAtOrAfterExpiry(): void
    {
        $reservation = $this->newReservation();

        $this->expectException(DomainException::class);
        $reservation->confirm($this->at('+2 hours'));
    }

    public function testHeldReservationExpiresOnlyAfterExpiryTime(): void
    {
        $reservation = $this->newReservation();

        self::assertFalse($reservation->expire($this->at('+1 hour')));
        self::assertSame(ReservationStatus::HELD, $reservation->status);
        self::assertTrue($reservation->expire($this->at('+2 hours')));
        self::assertSame(ReservationStatus::EXPIRED, $reservation->status);
        self::assertFalse($reservation->expire($this->at('+3 hours')));
    }

    public function testConfirmedReservationDoesNotExpireAutomatically(): void
    {
        $reservation = $this->newReservation();
        $reservation->confirm($this->at('+30 minutes'));

        self::assertFalse($reservation->expire($this->at('+3 hours')));
        self::assertSame(ReservationStatus::CONFIRMED, $reservation->status);
    }

    public function testConfirmedReservationCanBeMarkedFulfilled(): void
    {
        $reservation = $this->newReservation();
        $reservation->confirm($this->at('+30 minutes'));
        $reservation->markFulfilled($this->at('+3 hours'));

        self::assertSame(ReservationStatus::FULFILLED, $reservation->status);
        self::assertSame($this->at('+3 hours')->format(DATE_ATOM), $reservation->toArray()['fulfilledAt']);
    }

    public function testHeldReservationCanBeReleased(): void
    {
        $reservation = $this->newReservation();
        $reservation->release($this->at('+15 minutes'));

        self::assertSame(ReservationStatus::RELEASED, $reservation->status);
        self::assertSame($this->at('+15 minutes')->format(DATE_ATOM), $reservation->toArray()['releasedAt']);
    }

    public function testExpiredReservationCannotBeReleasedOrConfirmed(): void
    {
        $reservation = $this->newReservation();
        $reservation->expire($this->at('+2 hours'));

        $this->expectException(DomainException::class);
        $reservation->release($this->at('+3 hours'));
    }

    public function testCannotFulfilReservationBeforeConfirmation(): void
    {
        $reservation = $this->newReservation();

        $this->expectException(DomainException::class);
        $reservation->markFulfilled($this->at('+30 minutes'));
    }

    public function testRejectsInvalidIdentifiers(): void
    {
        $this->expectException(DomainException::class);
        new VehicleReservation('', 'tenant-demo', 'unit-1', 'variant-1', 'order-1', $this->at('+2 hours'));
    }

    private function newReservation(): VehicleReservation
    {
        return new VehicleReservation(
            'reservation-1',
            'tenant-demo',
            'vehicle-unit-1',
            'variant-1',
            'order-1',
            $this->at('+2 hours'),
        );
    }

    private function at(string $relative): \\DateTimeImmutable
    {
        return new \\DateTimeImmutable($relative, new \\DateTimeZone('UTC'));
    }
}
