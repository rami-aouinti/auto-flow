<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Infrastructure\Serialization\JsonSerializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonSerializerTest extends TestCase
{
    public function testItEncodesAndDecodesJsonPayloads(): void
    {
        $serializer = new JsonSerializer();
        $payload = $serializer->encode(['quoteId' => 'quote-123', 'amount' => 1200]);

        self::assertSame(
            ['quoteId' => 'quote-123', 'amount' => 1200],
            $serializer->decode($payload),
        );
    }

    public function testItRejectsMalformedJson(): void
    {
        $this->expectException(RuntimeException::class);

        (new JsonSerializer())->decode('{invalid');
    }
}
