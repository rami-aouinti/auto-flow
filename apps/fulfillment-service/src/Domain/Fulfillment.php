<?php

declare(strict_types=1);

namespace App\Domain;

use DomainException;

final class Fulfillment
{
    private bool $paymentCaptured = false;
    private bool $contractSigned = false;
    private bool $reservationConfirmed = false;
    private ?\DateTimeImmutable $paymentCapturedAt = null;
    private ?\DateTimeImmutable $contractSignedAt = null;
    private ?\DateTimeImmutable $reservationConfirmedAt = null;
    private ?\DateTimeImmutable $scheduledFor = null;
    private ?\DateTimeImmutable $dispatchedAt = null;
    private ?\DateTimeImmutable $deliveredAt = null;
    private ?string $carrier = null;
    private ?string $trackingReference = null;
    private ?string $recipientName = null;
    private ?string $deliveryFailureReason = null;

    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $orderId,
        public FulfillmentStatus $status = FulfillmentStatus::AWAITING_PREREQUISITES,
    ) {
        foreach (['id' => $id, 'tenantId' => $tenantId, 'orderId' => $orderId] as $field => $value) {
            if (trim($value) === '' || strlen($value) > 128) {
                throw new DomainException(sprintf('%s must be a non-empty identifier of at most 128 characters.', $field));
            }
        }
    }

    public function recordPaymentCaptured(\DateTimeImmutable $occurredAt): void
    {
        $this->assertNotTerminal();
        if (!$this->paymentCaptured) {
            $this->paymentCaptured = true;
            $this->paymentCapturedAt = $occurredAt;
        }
        $this->refreshReadiness();
    }

    public function recordContractSigned(\DateTimeImmutable $occurredAt): void
    {
        $this->assertNotTerminal();
        if (!$this->contractSigned) {
            $this->contractSigned = true;
            $this->contractSignedAt = $occurredAt;
        }
        $this->refreshReadiness();
    }

    public function recordReservationConfirmed(\DateTimeImmutable $occurredAt): void
    {
        $this->assertNotTerminal();
        if (!$this->reservationConfirmed) {
            $this->reservationConfirmed = true;
            $this->reservationConfirmedAt = $occurredAt;
        }
        $this->refreshReadiness();
    }

    public function startPreparation(): void
    {
        $this->transition(FulfillmentStatus::READY_FOR_PREPARATION, FulfillmentStatus::PREPARING);
    }

    public function completePreparation(): void
    {
        $this->transition(FulfillmentStatus::PREPARING, FulfillmentStatus::READY_FOR_DISPATCH);
    }

    public function scheduleDelivery(
        \DateTimeImmutable $scheduledFor,
        string $carrier,
        ?string $trackingReference = null,
    ): void {
        if (!in_array($this->status, [FulfillmentStatus::READY_FOR_DISPATCH, FulfillmentStatus::DELIVERY_FAILED], true)) {
            throw new DomainException(sprintf('Cannot schedule delivery while fulfillment is %s.', $this->status->value));
        }
        if (trim($carrier) === '' || strlen($carrier) > 120) {
            throw new DomainException('Carrier must contain between 1 and 120 characters.');
        }
        if ($scheduledFor <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new DomainException('Delivery must be scheduled in the future.');
        }
        if ($trackingReference !== null && (trim($trackingReference) === '' || strlen($trackingReference) > 180)) {
            throw new DomainException('Tracking reference must contain between 1 and 180 characters.');
        }

        $this->scheduledFor = $scheduledFor;
        $this->carrier = trim($carrier);
        $this->trackingReference = $trackingReference === null ? null : trim($trackingReference);
        $this->deliveryFailureReason = null;
        $this->status = FulfillmentStatus::SCHEDULED;
    }

    public function dispatch(\DateTimeImmutable $dispatchedAt): void
    {
        if ($this->status !== FulfillmentStatus::SCHEDULED) {
            throw new DomainException(sprintf('Cannot dispatch fulfillment while it is %s.', $this->status->value));
        }
        if ($this->scheduledFor === null || $dispatchedAt < $this->scheduledFor) {
            throw new DomainException('Fulfillment cannot be dispatched before its scheduled delivery time.');
        }

        $this->dispatchedAt = $dispatchedAt;
        $this->status = FulfillmentStatus::IN_TRANSIT;
    }

    public function confirmDelivery(string $recipientName, \DateTimeImmutable $deliveredAt): void
    {
        if ($this->status !== FulfillmentStatus::IN_TRANSIT) {
            throw new DomainException(sprintf('Cannot confirm delivery while fulfillment is %s.', $this->status->value));
        }
        if (trim($recipientName) === '' || strlen($recipientName) > 180) {
            throw new DomainException('Recipient name must contain between 1 and 180 characters.');
        }
        if ($this->dispatchedAt === null || $deliveredAt < $this->dispatchedAt) {
            throw new DomainException('Delivery confirmation cannot precede dispatch.');
        }

        $this->recipientName = trim($recipientName);
        $this->deliveredAt = $deliveredAt;
        $this->status = FulfillmentStatus::DELIVERED;
    }

    public function recordDeliveryFailure(string $reason): void
    {
        if ($this->status !== FulfillmentStatus::IN_TRANSIT) {
            throw new DomainException(sprintf('Cannot fail delivery while fulfillment is %s.', $this->status->value));
        }
        if (trim($reason) === '' || strlen($reason) > 500) {
            throw new DomainException('A delivery failure reason of at most 500 characters is required.');
        }

        $this->deliveryFailureReason = trim($reason);
        $this->status = FulfillmentStatus::DELIVERY_FAILED;
    }

    public function cancel(): void
    {
        if (in_array($this->status, [FulfillmentStatus::DELIVERED, FulfillmentStatus::IN_TRANSIT, FulfillmentStatus::CANCELLED], true)) {
            throw new DomainException(sprintf('Cannot cancel fulfillment while it is %s.', $this->status->value));
        }
        $this->status = FulfillmentStatus::CANCELLED;
    }

    public function hasPaymentCaptured(): bool
    {
        return $this->paymentCaptured;
    }

    public function hasContractSigned(): bool
    {
        return $this->contractSigned;
    }

    public function isReadyForPreparation(): bool
    {
        return $this->paymentCaptured && $this->contractSigned && $this->reservationConfirmed;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenantId' => $this->tenantId,
            'orderId' => $this->orderId,
            'status' => $this->status->value,
            'paymentCaptured' => $this->paymentCaptured,
            'contractSigned' => $this->contractSigned,
            'reservationConfirmed' => $this->reservationConfirmed,
            'paymentCapturedAt' => $this->paymentCapturedAt?->format(DATE_ATOM),
            'contractSignedAt' => $this->contractSignedAt?->format(DATE_ATOM),
            'reservationConfirmedAt' => $this->reservationConfirmedAt?->format(DATE_ATOM),
            'scheduledFor' => $this->scheduledFor?->format(DATE_ATOM),
            'dispatchedAt' => $this->dispatchedAt?->format(DATE_ATOM),
            'deliveredAt' => $this->deliveredAt?->format(DATE_ATOM),
            'carrier' => $this->carrier,
            'trackingReference' => $this->trackingReference,
            'recipientName' => $this->recipientName,
            'deliveryFailureReason' => $this->deliveryFailureReason,
        ];
    }

    private function refreshReadiness(): void
    {
        if ($this->status === FulfillmentStatus::AWAITING_PREREQUISITES && $this->isReadyForPreparation()) {
            $this->status = FulfillmentStatus::READY_FOR_PREPARATION;
        }
    }

    private function transition(FulfillmentStatus $expected, FulfillmentStatus $next): void
    {
        if ($this->status !== $expected) {
            throw new DomainException(sprintf('Cannot transition fulfillment from %s to %s.', $this->status->value, $next->value));
        }
        $this->status = $next;
    }

    private function assertNotTerminal(): void
    {
        if ($this->status->isTerminal()) {
            throw new DomainException(sprintf('Cannot update prerequisites for terminal fulfillment %s.', $this->status->value));
        }
    }
}
