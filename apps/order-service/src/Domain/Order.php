<?php

declare(strict_types=1);

namespace App\Domain;

use DomainException;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
final class Order
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $createdBy,
        public readonly string $offerId,
        public readonly string $quoteId,
        public readonly string $vehicleId,
        public readonly array $offerSnapshot,
        public readonly array $quoteSnapshot,
        public readonly array $vehicleSnapshot,
        public OrderStatus $status = OrderStatus::DRAFT,
        public ?\DateTimeImmutable $submittedAt = null,
        public ?\DateTimeImmutable $decidedAt = null,
        public ?string $decisionReason = null,
    ) {
        foreach ([$id, $tenantId, $createdBy, $offerId, $quoteId, $vehicleId] as $value) {
            if (trim($value) === '') {
                throw new DomainException('Order identifiers and actor must not be empty.');
            }
        }
        if ($offerSnapshot === [] || $quoteSnapshot === [] || $vehicleSnapshot === []) {
            throw new DomainException('Offer, quote and vehicle snapshots are required.');
        }
    }

    public function transitionTo(OrderStatus $next, ?string $reason = null): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new DomainException(sprintf(
                'Order cannot transition from %s to %s.',
                $this->status->value,
                $next->value,
            ));
        }
        if ($next === OrderStatus::REJECTED && ($reason === null || trim($reason) === '')) {
            throw new DomainException('A rejection reason is required.');
        }
        if ($reason !== null && strlen($reason) > 500) {
            throw new DomainException('Decision reason must not exceed 500 characters.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = $next;
        if ($next === OrderStatus::SUBMITTED) {
            $this->submittedAt = $now;
        }
        if (in_array($next, [OrderStatus::ACCEPTED, OrderStatus::REJECTED], true)) {
            $this->decidedAt = $now;
            $this->decisionReason = $reason;
        }
    }
}
