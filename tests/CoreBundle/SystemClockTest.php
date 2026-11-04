<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Infrastructure\Clock\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testItReturnsAnImmutableDateTime(): void
    {
        self::assertInstanceOf(DateTimeImmutable::class, (new SystemClock())->now());
    }
}
