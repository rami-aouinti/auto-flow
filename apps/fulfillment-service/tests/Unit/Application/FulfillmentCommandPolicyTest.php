<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\FulfillmentCommandPolicy;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FulfillmentCommandPolicyTest extends TestCase
{
    private FulfillmentCommandPolicy $policy;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->policy = new FulfillmentCommandPolicy();
        $this->now = new DateTimeImmutable('2026-11-08T12:00:00+00:00');
    }

    public function testPreparationRequiresReadyForPreparation(): void
    {
        $result = $this->policy->apply('start-preparation', ['status' => 'ready_for_preparation'], [], $this->now);

        self::assertSame('preparing', $result['status']);
    }

    public function testPreparationCannotStartBeforeBothPrerequisites(): void
    {
        $this->expectException(DomainException::class);
        $this->policy->apply('start-preparation', ['status' => 'awaiting_prerequisites'], [], $this->now);
    }

    public function testSchedulingRequiresFutureSlotAndCarrier(): void
    {
        $result = $this->policy->apply('schedule', ['status' => 'ready_for_dispatch'], [
            'scheduledFor' => '2026-11-09T10:00:00+01:00',
            'carrier' => 'AutoFlow Logistics',
            'trackingReference' => 'TRACK-123',
        ], $this->now);

        self::assertSame('scheduled', $result['status']);
        self::assertSame('AutoFlow Logistics', $result['changes']['carrier']);
        self::assertSame('2026-11-09 09:00:00+00:00', $result['changes']['scheduled_for']);
    }

    public function testRejectsNonIsoTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->apply('schedule', ['status' => 'ready_for_dispatch'], [
            'scheduledFor' => 'tomorrow',
            'carrier' => 'Carrier',
        ], $this->now);
    }

    public function testCannotScheduleInThePast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->apply('schedule', ['status' => 'ready_for_dispatch'], [
            'scheduledFor' => '2026-11-07T10:00:00Z',
            'carrier' => 'Carrier',
        ], $this->now);
    }

    public function testDispatchCannotHappenBeforeScheduledTime(): void
    {
        $this->expectException(DomainException::class);
        $this->policy->apply('dispatch', [
            'status' => 'scheduled',
            'scheduled_for' => '2026-11-08T13:00:00Z',
        ], [], $this->now);
    }

    public function testDeliveryConfirmationRequiresRecipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->policy->apply('confirm-delivery', [
            'status' => 'in_transit',
            'dispatched_at' => '2026-11-08T11:00:00Z',
        ], [], $this->now);
    }

    public function testCannotCancelAfterDispatch(): void
    {
        $this->expectException(DomainException::class);
        $this->policy->apply('cancel', ['status' => 'in_transit'], [], $this->now);
    }

    public function testFailedDeliveryCanBeRescheduled(): void
    {
        $result = $this->policy->apply('schedule', ['status' => 'delivery_failed'], [
            'scheduledFor' => '2026-11-10T10:00:00Z',
            'carrier' => 'Carrier B',
        ], $this->now);

        self::assertSame('scheduled', $result['status']);
        self::assertNull($result['changes']['delivery_failure_reason']);
    }
}
