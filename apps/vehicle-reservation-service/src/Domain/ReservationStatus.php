<?php

declare(strict_types=1);

namespace AutoFlow\\VehicleReservation\\Domain;

enum ReservationStatus: string
{
    case HELD = 'held';
    case CONFIRMED = 'confirmed';
    case EXPIRED = 'expired';
    case RELEASED = 'released';
    case FULFILLED = 'fulfilled';

    public function isActive(): bool
    {
        return in_array($this, [self::HELD, self::CONFIRMED], true);
    }

    public function isTerminal(): bool
    {
        return !$this->isActive();
    }
}
