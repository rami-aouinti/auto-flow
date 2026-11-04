<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\PricingQuote;
use App\Domain\ValueObject\LeaseTerms;
use App\Domain\ValueObject\Money;
use InvalidArgumentException;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
final class LeasePaymentCalculator
{
    public function calculate(string $vehicleId, LeaseTerms $terms): PricingQuote
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $vehicleId)) {
            throw new InvalidArgumentException('Vehicle ID has an invalid format.');
        }

        $monthlyRate = $terms->annualInterestRateBasisPoints / 10_000 / 12;
        $financeableAmount = $terms->vehiclePrice->minorUnits - $terms->downPayment->minorUnits;

        if ($monthlyRate === 0.0) {
            $monthlyAmount = ($financeableAmount - $terms->residualValue->minorUnits) / $terms->durationMonths;
        } else {
            $discountFactor = (1 + $monthlyRate) ** (-$terms->durationMonths);
            $presentValue = $financeableAmount - ($terms->residualValue->minorUnits * $discountFactor);
            $monthlyAmount = ($presentValue * $monthlyRate) / (1 - $discountFactor);
        }

        $monthlyMinorUnits = (int) round($monthlyAmount, 0, \RoundingMode::HalfAwayFromZero);

        if ($monthlyMinorUnits > intdiv(PHP_INT_MAX, $terms->durationMonths)) {
            throw new InvalidArgumentException('Calculated total exceeds the supported monetary range.');
        }

        $monthlyPayment = Money::fromMinorUnits($monthlyMinorUnits, $terms->vehiclePrice->currency);
        $totalInstallments = Money::fromMinorUnits(
            $monthlyMinorUnits * $terms->durationMonths,
            $terms->vehiclePrice->currency,
        );

        return new PricingQuote(
            vehicleId: $vehicleId,
            monthlyAmount: $monthlyPayment,
            totalInstallmentsAmount: $totalInstallments,
            totalCostIncludingDownPayment: $totalInstallments->plus($terms->downPayment),
            residualValue: $terms->residualValue,
            durationMonths: $terms->durationMonths,
            annualInterestRateBasisPoints: $terms->annualInterestRateBasisPoints,
            calculationVersion: 'lease-annuity-v1',
        );
    }
}
