<?php
declare(strict_types=1);

namespace App\Domain;

enum InventoryStatus: string
{
    case AVAILABLE = 'available';
    case BLOCKED = 'blocked';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case SOLD = 'sold';
    case MAINTENANCE = 'maintenance';
    case QUARANTINED = 'quarantined';

    public function canTransitionTo(self $target): bool
    {
        $allowed = match ($this) {
            self::AVAILABLE => [self::BLOCKED, self::IN_TRANSIT, self::MAINTENANCE, self::QUARANTINED, self::SOLD],
            self::BLOCKED => [self::AVAILABLE, self::MAINTENANCE, self::QUARANTINED],
            self::IN_TRANSIT => [self::AVAILABLE, self::DELIVERED, self::QUARANTINED],
            self::DELIVERED => [self::AVAILABLE, self::SOLD, self::MAINTENANCE, self::QUARANTINED],
            self::MAINTENANCE => [self::AVAILABLE, self::QUARANTINED],
            self::QUARANTINED => [self::MAINTENANCE, self::AVAILABLE],
            self::SOLD => [],
        };

        return in_array($target, $allowed, true);
    }
}
