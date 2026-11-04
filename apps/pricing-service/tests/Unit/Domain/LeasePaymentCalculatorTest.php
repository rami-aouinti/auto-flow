<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Service\LeasePaymentCalculator;
use App\Domain\ValueObject\LeaseTerms;
use App\Domain\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeasePaymentCalculator::class)]
/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class LeasePaymentCalculatorTest extends TestCase
{
    public function testZeroInterestRateCalculatesStraightLineDepreciationForTenant(): void
    {
        $terms = new LeaseTerms(
            vehiclePrice: Money::fromDecimal('30000.00', 'EUR'),
            downPayment: Money::fromDecimal('3000.00', 'EUR'),
            residualValue: Money::fromDecimal('9000.00', 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 0,
        );

        $quote = (new LeasePaymentCalculator())->calculate(
            '0123456789abcdef0123456789abcdef',
            'tenant-a',
            'user-a',
            'vehicle-001',
            $terms,
        );

        self::assertSame('0123456789abcdef0123456789abcdef', $quote->quoteId);
        self::assertSame('tenant-a', $quote->tenantId);
        self::assertSame('user-a', $quote->createdBy);
        self::assertSame('vehicle-001', $quote->vehicleId);
        self::assertSame('500.00', $quote->monthlyAmount->toDecimal());
        self::assertSame('18000.00', $quote->totalInstallmentsAmount->toDecimal());
        self::assertSame('21000.00', $quote->totalCostIncludingDownPayment->toDecimal());
        self::assertSame('lease-annuity-v1', $quote->calculationVersion);
    }

    public function testPositiveInterestRateIncreasesMonthlyPayment(): void
    {
        $terms = new LeaseTerms(
            vehiclePrice: Money::fromDecimal('30000.00', 'EUR'),
            downPayment: Money::fromDecimal('3000.00', 'EUR'),
            residualValue: Money::fromDecimal('9000.00', 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 500,
        );

        $quote = (new LeasePaymentCalculator())->calculate(
            '0123456789abcdef0123456789abcdef',
            'tenant-a',
            'user-a',
            'vehicle-001',
            $terms,
        );

        self::assertGreaterThan(50000, $quote->monthlyAmount->minorUnits);
        self::assertSame('EUR', $quote->monthlyAmount->currency);
    }

    public function testRejectsInvalidTenantIdentifier(): void
    {
        $terms = new LeaseTerms(
            vehiclePrice: Money::fromDecimal('30000.00', 'EUR'),
            downPayment: Money::fromDecimal('3000.00', 'EUR'),
            residualValue: Money::fromDecimal('9000.00', 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 500,
        );

        $this->expectException(InvalidArgumentException::class);

        (new LeasePaymentCalculator())->calculate(
            '0123456789abcdef0123456789abcdef',
            '../tenant-b',
            'vehicle-001',
            $terms,
        );
    }

    public function testRejectsInvalidQuoteIdentifier(): void
    {
        $terms = new LeaseTerms(
            vehiclePrice: Money::fromDecimal('30000.00', 'EUR'),
            downPayment: Money::fromDecimal('3000.00', 'EUR'),
            residualValue: Money::fromDecimal('9000.00', 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 500,
        );

        $this->expectException(InvalidArgumentException::class);

        (new LeasePaymentCalculator())->calculate('invalid-id', 'tenant-a', 'user-a', 'vehicle-001', $terms);
    }

    public function testRejectsDownPaymentGreaterThanVehiclePrice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LeaseTerms(
            vehiclePrice: Money::fromDecimal('10000.00', 'EUR'),
            downPayment: Money::fromDecimal('10001.00', 'EUR'),
            residualValue: Money::fromDecimal('0.00', 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 500,
        );
    }

    public function testRejectsMixedCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LeaseTerms(
            vehiclePrice: Money::fromDecimal('30000.00', 'EUR'),
            downPayment: Money::fromDecimal('3000.00', 'USD'),
            residualValue: Money::fromDecimal('9000.00', 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 500,
        );
    }

    public function testRejectsMoneyWithMoreThanTwoDecimalPlaces(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('12.345', 'EUR');
    }
}
