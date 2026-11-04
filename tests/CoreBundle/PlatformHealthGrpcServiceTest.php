<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Transport\Grpc\PlatformHealthGrpcService;
use Google\Protobuf\GPBEmpty;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\GRPC\ContextInterface;

final class PlatformHealthGrpcServiceTest extends TestCase
{
    public function testPingReturnsAnEmptyProtobufMessage(): void
    {
        $service = new PlatformHealthGrpcService();
        $context = $this->createMock(ContextInterface::class);

        self::assertInstanceOf(GPBEmpty::class, $service->Ping($context, new GPBEmpty()));
    }
}
