<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\Money;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 */
final readonly class PricingQuote
{
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
    ) {
    }
}
