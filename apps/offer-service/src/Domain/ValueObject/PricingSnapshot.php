<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Immutable copy of the commercial terms returned by Pricing.
 *
 * The snapshot deliberately contains no Pricing service implementation details.
 */
final readonly class PricingSnapshot
{
    public function __construct(
        public string $quoteId,
        public string $vehicleId,
        public string $monthlyAmount,
        public string $totalInstallmentsAmount,
        public string $totalCostIncludingDownPayment,
        public string $residualValue,
        public string $currency,
        public int $durationMonths,
        public int $annualInterestRateBasisPoints,
        public string $calculationVersion,
    ) {
        foreach ([
            'quoteId' => $quoteId,
            'vehicleId' => $vehicleId,
            'calculationVersion' => $calculationVersion,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('%s must not be empty.', $field));
            }
        }

        foreach ([
            'monthlyAmount' => $monthlyAmount,
            'totalInstallmentsAmount' => $totalInstallmentsAmount,
            'totalCostIncludingDownPayment' => $totalCostIncludingDownPayment,
            'residualValue' => $residualValue,
        ] as $field => $amount) {
            if (preg_match('/^(0|[1-9][0-9]*)\.[0-9]{2}$/D', $amount) !== 1) {
                throw new InvalidArgumentException(sprintf('%s must be a non-negative decimal with two fractional digits.', $field));
            }
        }

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase ISO code.');
        }

        if ($durationMonths < 1 || $durationMonths > 120) {
            throw new InvalidArgumentException('Duration must be between 1 and 120 months.');
        }

        if ($annualInterestRateBasisPoints < 0) {
            throw new InvalidArgumentException('Annual interest rate cannot be negative.');
        }
    }
}
