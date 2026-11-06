<?php

declare(strict_types=1);

namespace App\Domain;

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case UNDER_REVIEW = 'under_review';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CONTRACT_PENDING = 'contract_pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::SUBMITTED, self::CANCELLED],
            self::SUBMITTED => [self::UNDER_REVIEW, self::ACCEPTED, self::REJECTED, self::CANCELLED],
            self::UNDER_REVIEW => [self::ACCEPTED, self::REJECTED, self::CANCELLED],
            self::ACCEPTED => [self::CONTRACT_PENDING, self::CANCELLED],
            self::CONTRACT_PENDING => [self::COMPLETED, self::CANCELLED],
            self::REJECTED, self::COMPLETED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
