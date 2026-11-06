<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Order;
use App\Domain\OrderStatus;
use DomainException;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private function order(): Order
    {
        return new Order(
            'order-1', 'tenant-1', 'customer-1', 'offer-1', 'quote-1', 'vehicle-1',
            ['version' => 1], ['currency' => 'EUR'], ['variant' => 'EV'],
        );
    }

    public function testDraftCanBeSubmittedAndTimestampIsRecorded(): void
    {
        $order = $this->order();
        $order->transitionTo(OrderStatus::SUBMITTED);

        self::assertSame(OrderStatus::SUBMITTED, $order->status);
        self::assertNotNull($order->submittedAt);
    }

    public function testRejectionRequiresReason(): void
    {
        $order = $this->order();
        $order->transitionTo(OrderStatus::SUBMITTED);
        $order->transitionTo(OrderStatus::UNDER_REVIEW);

        $this->expectException(DomainException::class);
        $order->transitionTo(OrderStatus::REJECTED);
    }

    public function testRejectedOrderIsTerminal(): void
    {
        $order = $this->order();
        $order->transitionTo(OrderStatus::SUBMITTED);
        $order->transitionTo(OrderStatus::UNDER_REVIEW);
        $order->transitionTo(OrderStatus::REJECTED, 'Eligibility criteria not met.');

        self::assertSame(OrderStatus::REJECTED, $order->status);
        self::assertNotNull($order->decidedAt);
        self::assertSame('Eligibility criteria not met.', $order->decisionReason);

        $this->expectException(DomainException::class);
        $order->transitionTo(OrderStatus::ACCEPTED);
    }

    public function testCannotSkipFromDraftToAccepted(): void
    {
        $order = $this->order();

        $this->expectException(DomainException::class);
        $order->transitionTo(OrderStatus::ACCEPTED);
    }
}
