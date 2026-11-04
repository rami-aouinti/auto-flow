<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Service\LeasePaymentCalculator;
use App\Domain\ValueObject\LeaseTerms;
use App\Domain\ValueObject\Money;
use App\Tests\Fixtures\PricingPolicyFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LeasePaymentCalculatorPolicyTest extends TestCase
{
    public function testQuoteCopiesActivePolicyVersionAndValidityIntoSnapshot(): void
    {
        $policy = PricingPolicyFixture::draft(validityDays: 14)->activate(
            new DateTimeImmutable('2026-11-04T12:00:00+00:00'),
        );
        $terms = new LeaseTerms(
            vehiclePrice: Money::fromDecimal('30000.00', 'EUR'),
            downPayment: Money::fromDecimal('5000.00', 'EUR'),
            residualValue: Money::fromDecimal('12000.00', 'EUR'),
            durationMonths: 48,
            annualInterestRateBasisPoints: 450,
        );

        $quote = (new LeasePaymentCalculator())->calculate(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'tenant-demo',
            'swagger-demo-user',
            'vehicle-demo-001',
            $terms,
            $policy,
        );

        self::assertSame($policy->policyId, $quote->policyId);
        self::assertSame($policy->version, $quote->policyVersion);
        self::assertSame('lease-annuity-v1', $quote->calculationVersion);
        self::assertSame(14, (int) $quote->createdAt->diff($quote->expiresAt)->format('%a'));
    }
}
