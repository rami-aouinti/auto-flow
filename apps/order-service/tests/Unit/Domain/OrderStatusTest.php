<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testDraftCanBeSubmittedOrCancelled(): void
    {
        self::assertTrue(OrderStatus::DRAFT->canTransitionTo(OrderStatus::SUBMITTED));
        self::assertTrue(OrderStatus::DRAFT->canTransitionTo(OrderStatus::CANCELLED));
        self::assertFalse(OrderStatus::DRAFT->canTransitionTo(OrderStatus::COMPLETED));
    }

    public function testTerminalStatesCannotTransition(): void
    {
        self::assertSame([], OrderStatus::COMPLETED->allowedTransitions());
        self::assertSame([], OrderStatus::REJECTED->allowedTransitions());
        self::assertSame([], OrderStatus::CANCELLED->allowedTransitions());
    }
}
