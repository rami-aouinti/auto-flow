<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Infrastructure\Serialization\JsonSerializer;
use InvalidArgumentException;
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

    public function testItRejectsTypedHydrationInsteadOfIgnoringTheType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JsonSerializer())->decode('{"id":"123"}', ExampleDto::class);
    }

    public function testItRejectsMalformedJson(): void
    {
        $this->expectException(RuntimeException::class);

        (new JsonSerializer())->decode('{invalid');
    }

    public function testItRejectsAnInvalidDecodeDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonSerializer(decodeDepth: 0);
    }
}

final readonly class ExampleDto
{
    public function __construct(public string $id)
    {
    }
}
