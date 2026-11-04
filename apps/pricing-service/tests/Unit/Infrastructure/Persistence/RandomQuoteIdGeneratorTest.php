<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence;

use App\Infrastructure\Persistence\RandomQuoteIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RandomQuoteIdGenerator::class)]
final class RandomQuoteIdGeneratorTest extends TestCase
{
    public function testGeneratesUniqueThirtyTwoCharacterHexadecimalIdentifiers(): void
    {
        $generator = new RandomQuoteIdGenerator();

        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $first);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $second);
        self::assertNotSame($first, $second);
    }
}
