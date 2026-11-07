<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Domain\Model\PricingPolicy;
use DateTimeImmutable;

final class PricingPolicyFixture
{
    public static function draft(
        string $tenantId = 'tenant-demo',
        int $version = 1,
        string $policyId = '123e4567-e89b-42d3-a456-426614174000',
        int $validityDays = 7,
        string $calculationVersion = 'lease-annuity-v1',
    ): PricingPolicy {
        return new PricingPolicy(
            policyId: $policyId,
            tenantId: $tenantId,
            version: $version,
            status: 'draft',
            minimumDurationMonths: 12,
            maximumDurationMonths: 72,
            minimumAnnualRateBasisPoints: 0,
            maximumAnnualRateBasisPoints: 2000,
            quoteValidityDays: $validityDays,
            calculationVersion: $calculationVersion,
            createdBy: 'policy-admin-demo',
            createdAt: new DateTimeImmutable('2026-11-04T12:00:00+00:00'),
        );
    }
}
