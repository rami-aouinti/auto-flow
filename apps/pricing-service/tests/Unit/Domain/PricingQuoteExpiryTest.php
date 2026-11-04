<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Tests\Fixtures\PricingQuoteFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PricingQuoteExpiryTest extends TestCase
{
    public function testNewQuoteExpiresAfterSevenDaysByDefault(): void
    {
        $createdAt = new DateTimeImmutable('2026-11-04T12:00:00+00:00');
        $quote = PricingQuoteFixture::demo(useDefaultExpiry: true);

        self::assertSame('2026-11-11T12:00:00+00:00', $quote->expiresAt->format(DATE_ATOM));
        self::assertFalse($quote->isExpired($createdAt->modify('+6 days')));
        self::assertTrue($quote->isExpired($createdAt->modify('+7 days')));
    }

    public function testExpiredFixtureIsExpiredAtCurrentTestInstant(): void
    {
        $quote = PricingQuoteFixture::expired();

        self::assertTrue($quote->isExpired(new DateTimeImmutable('2026-11-04T12:00:00+00:00')));
    }
}
