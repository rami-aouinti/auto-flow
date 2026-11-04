<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Domain\Model\PricingQuote;
use App\Domain\ValueObject\Money;
use DateTimeImmutable;

/** Deterministic fixture factory shared by unit and integration tests. */
final class PricingQuoteFixture
{
    public static function demo(
        string $quoteId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        string $tenantId = 'tenant-demo',
        string $ownerId = 'swagger-demo-user',
        string $vehicleId = 'vehicle-demo-001',
    ): PricingQuote {
        $createdAt = new DateTimeImmutable('2026-11-04T12:00:00+00:00');

        return new PricingQuote(
            quoteId: $quoteId,
            tenantId: $tenantId,
            vehicleId: $vehicleId,
            monthlyAmount: Money::fromMinorUnits(45546, 'EUR'),
            totalInstallmentsAmount: Money::fromMinorUnits(2186208, 'EUR'),
            totalCostIncludingDownPayment: Money::fromMinorUnits(2686208, 'EUR'),
            residualValue: Money::fromMinorUnits(1200000, 'EUR'),
            durationMonths: 48,
            annualInterestRateBasisPoints: 450,
            calculationVersion: 'lease-annuity-v1',
            createdBy: $ownerId,
            vehiclePrice: Money::fromMinorUnits(3000000, 'EUR'),
            downPayment: Money::fromMinorUnits(500000, 'EUR'),
            createdAt: $createdAt,
            expiresAt: $createdAt->modify('+7 days'),
            policyId: '123e4567-e89b-42d3-a456-426614174000',
            policyVersion: 1,
        );
    }

    public static function expired(): PricingQuote
    {
        $quote = self::demo('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'tenant-demo', 'swagger-demo-user', 'vehicle-demo-002');
        $createdAt = new DateTimeImmutable('2026-10-01T12:00:00+00:00');

        return new PricingQuote(
            quoteId: $quote->quoteId,
            tenantId: $quote->tenantId,
            vehicleId: $quote->vehicleId,
            monthlyAmount: $quote->monthlyAmount,
            totalInstallmentsAmount: $quote->totalInstallmentsAmount,
            totalCostIncludingDownPayment: $quote->totalCostIncludingDownPayment,
            residualValue: $quote->residualValue,
            durationMonths: $quote->durationMonths,
            annualInterestRateBasisPoints: $quote->annualInterestRateBasisPoints,
            calculationVersion: $quote->calculationVersion,
            createdBy: $quote->createdBy,
            vehiclePrice: $quote->vehiclePrice,
            downPayment: $quote->downPayment,
            createdAt: $createdAt,
            expiresAt: $createdAt->modify('+7 days'),
        );
    }
}
