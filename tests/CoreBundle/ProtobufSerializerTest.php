<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Infrastructure\Serialization\ProtobufSerializer;
use Google\Protobuf\GPBEmpty;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProtobufSerializerTest extends TestCase
{
    public function testItEncodesAndDecodesGeneratedMessages(): void
    {
        $serializer = new ProtobufSerializer();
        $payload = $serializer->encode(new GPBEmpty());
        $decoded = $serializer->decode($payload, GPBEmpty::class);

        self::assertInstanceOf(GPBEmpty::class, $decoded);
    }

    public function testItRequiresAnExplicitGeneratedMessageClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProtobufSerializer())->decode('', null);
    }
}
