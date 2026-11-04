<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

final readonly class LeaseTerms
{
    public function __construct(
        public Money $vehiclePrice,
        public Money $downPayment,
        public Money $residualValue,
        public int $durationMonths,
        public int $annualInterestRateBasisPoints,
    ) {
        if ($vehiclePrice->currency !== $downPayment->currency || $vehiclePrice->currency !== $residualValue->currency) {
            throw new InvalidArgumentException('All lease amounts must use the same currency.');
        }

        if ($downPayment->minorUnits > $vehiclePrice->minorUnits) {
            throw new InvalidArgumentException('Down payment cannot exceed the vehicle price.');
        }

        $financeableAmount = $vehiclePrice->minorUnits - $downPayment->minorUnits;

        if ($residualValue->minorUnits > $financeableAmount) {
            throw new InvalidArgumentException('Residual value cannot exceed the price after down payment.');
        }

        if ($durationMonths < 12 || $durationMonths > 72) {
            throw new InvalidArgumentException('Lease duration must be between 12 and 72 months.');
        }

        if ($annualInterestRateBasisPoints < 0 || $annualInterestRateBasisPoints > 2000) {
            throw new InvalidArgumentException('Annual interest rate must be between 0 and 2000 basis points.');
        }
    }
}
