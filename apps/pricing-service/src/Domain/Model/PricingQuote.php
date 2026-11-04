<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
final readonly class PricingQuote
{
    public DateTimeImmutable $createdAt;

    public DateTimeImmutable $expiresAt;

    public function __construct(
        public string $quoteId,
        public string $tenantId,
        public string $vehicleId,
        public Money $monthlyAmount,
        public Money $totalInstallmentsAmount,
        public Money $totalCostIncludingDownPayment,
        public Money $residualValue,
        public int $durationMonths,
        public int $annualInterestRateBasisPoints,
        public string $calculationVersion,
        public string $createdBy,
        public ?Money $vehiclePrice = null,
        public ?Money $downPayment = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $expiresAt = null,
        public ?string $policyId = null,
        public ?int $policyVersion = null,
        int $validityDays = 7,
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable('now');
        if ($validityDays < 1 || $validityDays > 90) {
            throw new \\InvalidArgumentException('Quote validity must be between 1 and 90 days.');
        }
        $this->expiresAt = $expiresAt ?? $this->createdAt->modify(sprintf('+%d days', $validityDays));
        if (($this->policyId === null) !== ($this->policyVersion === null) || ($this->policyVersion !== null && $this->policyVersion < 1)) {
            throw new \\InvalidArgumentException('Quote policy ID and version must be provided together.');
        }

        if ($this->expiresAt <= $this->createdAt) {
            throw new \InvalidArgumentException('Quote expiry must be later than its creation time.');
        }

        foreach ([$this->vehiclePrice, $this->downPayment] as $snapshotAmount) {
            if ($snapshotAmount !== null && $snapshotAmount->currency !== $this->monthlyAmount->currency) {
                throw new \InvalidArgumentException('Quote snapshot amounts must use the quote currency.');
            }
        }
    }

    public function isExpired(?DateTimeImmutable $at = null): bool
    {
        return ($at ?? new DateTimeImmutable('now')) >= $this->expiresAt;
    }
}
