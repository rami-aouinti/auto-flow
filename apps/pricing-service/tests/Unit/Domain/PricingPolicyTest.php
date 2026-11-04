<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Tests\Fixtures\PricingPolicyFixture;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PricingPolicyTest extends TestCase
{
    public function testPolicyAcceptsTermsInsideConfiguredRange(): void
    {
        self::assertTrue(PricingPolicyFixture::draft()->allows(48, 450));
    }

    public function testPolicyRejectsTermsOutsideConfiguredRange(): void
    {
        self::assertFalse(PricingPolicyFixture::draft()->allows(84, 450));
        self::assertFalse(PricingPolicyFixture::draft()->allows(48, 2500));
    }

    public function testDraftCanBePublishedAndThenRetiredWithoutLosingPublicationTime(): void
    {
        $publishedAt = new DateTimeImmutable('2026-11-04T13:00:00+00:00');
        $active = PricingPolicyFixture::draft()->activate($publishedAt);
        $retired = $active->retire();

        self::assertSame('active', $active->status);
        self::assertSame('retired', $retired->status);
        self::assertSame($publishedAt, $retired->publishedAt);
    }

    public function testPolicyRejectsAnAlgorithmVersionWithoutAnImplementedCalculator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingPolicyFixture::draft(calculationVersion: 'lease-annuity-v2');
    }

    public function testActivePolicyCannotBePublishedTwice(): void
    {
        $active = PricingPolicyFixture::draft()->activate(new DateTimeImmutable('2026-11-04T13:00:00+00:00'));
        $this->expectException(InvalidArgumentException::class);
        $active->activate(new DateTimeImmutable('2026-11-04T14:00:00+00:00'));
    }
}
