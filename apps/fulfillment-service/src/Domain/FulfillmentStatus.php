<?php

declare(strict_types=1);

namespace App\Domain;

enum FulfillmentStatus: string
{
    case AWAITING_PREREQUISITES = 'awaiting_prerequisites';
    case READY_FOR_PREPARATION = 'ready_for_preparation';
    case PREPARING = 'preparing';
    case READY_FOR_DISPATCH = 'ready_for_dispatch';
    case SCHEDULED = 'scheduled';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case DELIVERY_FAILED = 'delivery_failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::DELIVERED, self::CANCELLED], true);
    }
}
