<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Fulfillment;
use App\Domain\FulfillmentStatus;
use DomainException;
use PHPUnit\Framework\TestCase;

final class FulfillmentTest extends TestCase
{
    public function testFulfillmentWaitsUntilBothPaymentAndContractAreConfirmed(): void
    {
        $fulfillment = $this->newFulfillment();

        $fulfillment->recordPaymentCaptured($this->at('-1 hour'));

        self::assertSame(FulfillmentStatus::AWAITING_PREREQUISITES, $fulfillment->status);
        self::assertFalse($fulfillment->isReadyForPreparation());

        $fulfillment->recordContractSigned($this->at('-30 minutes'));
        $fulfillment->recordReservationConfirmed($this->at('-15 minutes'));

        self::assertSame(FulfillmentStatus::READY_FOR_PREPARATION, $fulfillment->status);
        self::assertTrue($fulfillment->isReadyForPreparation());
    }

    public function testPrerequisitesCanArriveInReverseOrder(): void
    {
        $fulfillment = $this->newFulfillment();

        $fulfillment->recordContractSigned($this->at('-30 minutes'));
        self::assertSame(FulfillmentStatus::AWAITING_PREREQUISITES, $fulfillment->status);

        $fulfillment->recordPaymentCaptured($this->at('-1 hour'));
        $fulfillment->recordReservationConfirmed($this->at('-15 minutes'));

        self::assertSame(FulfillmentStatus::READY_FOR_PREPARATION, $fulfillment->status);
    }

    public function testDuplicatePrerequisiteEventsAreIdempotentAtDomainLevel(): void
    {
        $fulfillment = $this->newFulfillment();
        $first = $this->at('-1 hour');

        $fulfillment->recordPaymentCaptured($first);
        $fulfillment->recordPaymentCaptured($this->at('-10 minutes'));
        $fulfillment->recordContractSigned($first);
        $fulfillment->recordContractSigned($this->at('-10 minutes'));
        $fulfillment->recordReservationConfirmed($first);
        $fulfillment->recordReservationConfirmed($this->at('-10 minutes'));

        self::assertSame($first->format(DATE_ATOM), $fulfillment->toArray()['paymentCapturedAt']);
        self::assertSame($first->format(DATE_ATOM), $fulfillment->toArray()['contractSignedAt']);
        self::assertSame($first->format(DATE_ATOM), $fulfillment->toArray()['reservationConfirmedAt']);
        self::assertSame(FulfillmentStatus::READY_FOR_PREPARATION, $fulfillment->status);
    }

    public function testFullDeliveryLifecycle(): void
    {
        $fulfillment = $this->readyFulfillment();
        $fulfillment->startPreparation();
        $fulfillment->completePreparation();

        $slot = $this->at('+2 days');
        $fulfillment->scheduleDelivery($slot, 'AutoFlow Logistics', 'TRACK-123');
        $fulfillment->dispatch($slot);
        $fulfillment->confirmDelivery('Alex Customer', $slot->modify('+1 hour'));

        self::assertSame(FulfillmentStatus::DELIVERED, $fulfillment->status);
        self::assertSame('Alex Customer', $fulfillment->toArray()['recipientName']);
        self::assertSame('TRACK-123', $fulfillment->toArray()['trackingReference']);
    }

    public function testFailedDeliveryCanBeRescheduled(): void
    {
        $fulfillment = $this->readyFulfillment();
        $fulfillment->startPreparation();
        $fulfillment->completePreparation();
        $slot = $this->at('+2 days');
        $fulfillment->scheduleDelivery($slot, 'Carrier A');
        $fulfillment->dispatch($slot);
        $fulfillment->recordDeliveryFailure('Customer was unavailable.');

        self::assertSame(FulfillmentStatus::DELIVERY_FAILED, $fulfillment->status);
        $fulfillment->scheduleDelivery($this->at('+3 days'), 'Carrier B');

        self::assertSame(FulfillmentStatus::SCHEDULED, $fulfillment->status);
        self::assertNull($fulfillment->toArray()['deliveryFailureReason']);
    }

    public function testCannotPrepareBeforePrerequisitesAreSatisfied(): void
    {
        $fulfillment = $this->newFulfillment();

        $this->expectException(DomainException::class);
        $fulfillment->startPreparation();
    }

    public function testCannotConfirmDeliveryBeforeDispatch(): void
    {
        $fulfillment = $this->readyFulfillment();

        $this->expectException(DomainException::class);
        $fulfillment->confirmDelivery('Alex Customer', $this->at('+2 days'));
    }

    public function testCannotCancelAfterDispatchOrDelivery(): void
    {
        $fulfillment = $this->readyFulfillment();
        $fulfillment->startPreparation();
        $fulfillment->completePreparation();
        $slot = $this->at('+2 days');
        $fulfillment->scheduleDelivery($slot, 'Carrier A');
        $fulfillment->dispatch($slot);

        $this->expectException(DomainException::class);
        $fulfillment->cancel();
    }

    public function testRejectsPastDeliverySlot(): void
    {
        $fulfillment = $this->readyFulfillment();
        $fulfillment->startPreparation();
        $fulfillment->completePreparation();

        $this->expectException(DomainException::class);
        $fulfillment->scheduleDelivery($this->at('-1 day'), 'Carrier A');
    }

    private function newFulfillment(): Fulfillment
    {
        return new Fulfillment('fulfillment-1', 'tenant-demo', 'order-1');
    }

    private function readyFulfillment(): Fulfillment
    {
        $fulfillment = $this->newFulfillment();
        $occurredAt = $this->at('-1 hour');
        $fulfillment->recordPaymentCaptured($occurredAt);
        $fulfillment->recordContractSigned($occurredAt);
        $fulfillment->recordReservationConfirmed($occurredAt);

        return $fulfillment;
    }

    private function at(string $relative): \DateTimeImmutable
    {
        return new \DateTimeImmutable($relative, new \DateTimeZone('UTC'));
    }
}
