<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\PricingPolicy;
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
    public function calculate(
        string $quoteId,
        string $tenantId,
        string $createdBy,
        string $vehicleId,
        LeaseTerms $terms,
        ?PricingPolicy $policy = null,
    ): PricingQuote {
        if (!preg_match('/^[a-f0-9]{32}$/D', $quoteId)) {
            throw new InvalidArgumentException('Quote ID has an invalid format.');
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)) {
            throw new InvalidArgumentException('Tenant ID has an invalid format.');
        }

        if (trim($createdBy) === '' || strlen($createdBy) > 255) {
            throw new InvalidArgumentException('Quote creator identifier has an invalid format.');
        }

        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $vehicleId)) {
            throw new InvalidArgumentException('Vehicle ID has an invalid format.');
        }

        $monthlyRate = $terms->annualInterestRateBasisPoints / 10_000 / 12;
        $financeableAmount = $terms->vehiclePrice->minorUnits - $terms->downPayment->minorUnits;

        if ($terms->annualInterestRateBasisPoints === 0) {
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
            quoteId: $quoteId,
            tenantId: $tenantId,
            vehicleId: $vehicleId,
            monthlyAmount: $monthlyPayment,
            totalInstallmentsAmount: $totalInstallments,
            totalCostIncludingDownPayment: $totalInstallments->plus($terms->downPayment),
            residualValue: $terms->residualValue,
            durationMonths: $terms->durationMonths,
            annualInterestRateBasisPoints: $terms->annualInterestRateBasisPoints,
            calculationVersion: $policy?->calculationVersion ?? 'lease-annuity-v1',
            createdBy: $createdBy,
            vehiclePrice: $terms->vehiclePrice,
            downPayment: $terms->downPayment,
            policyId: $policy?->policyId,
            policyVersion: $policy?->version,
            validityDays: $policy?->quoteValidityDays ?? 7,
        );
    }
}
