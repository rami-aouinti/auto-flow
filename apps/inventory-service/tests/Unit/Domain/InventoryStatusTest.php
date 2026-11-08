<?php
declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\InventoryStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryStatusTest extends TestCase
{
    #[DataProvider('validTransitions')]
    public function testAllowsValidTransitions(InventoryStatus $from, InventoryStatus $to): void
    {
        self::assertTrue($from->canTransitionTo($to));
    }

    #[DataProvider('invalidTransitions')]
    public function testRejectsInvalidTransitions(InventoryStatus $from, InventoryStatus $to): void
    {
        self::assertFalse($from->canTransitionTo($to));
    }

    public static function validTransitions(): iterable
    {
        yield 'available to blocked' => [InventoryStatus::AVAILABLE, InventoryStatus::BLOCKED];
        yield 'available to sold' => [InventoryStatus::AVAILABLE, InventoryStatus::SOLD];
        yield 'in transit to delivered' => [InventoryStatus::IN_TRANSIT, InventoryStatus::DELIVERED];
        yield 'maintenance to available' => [InventoryStatus::MAINTENANCE, InventoryStatus::AVAILABLE];
        yield 'quarantined to maintenance' => [InventoryStatus::QUARANTINED, InventoryStatus::MAINTENANCE];
    }

    public static function invalidTransitions(): iterable
    {
        yield 'sold is terminal' => [InventoryStatus::SOLD, InventoryStatus::AVAILABLE];
        yield 'available cannot be delivered directly' => [InventoryStatus::AVAILABLE, InventoryStatus::DELIVERED];
        yield 'blocked cannot be sold' => [InventoryStatus::BLOCKED, InventoryStatus::SOLD];
        yield 'in transit cannot be maintenance' => [InventoryStatus::IN_TRANSIT, InventoryStatus::MAINTENANCE];
    }
}
