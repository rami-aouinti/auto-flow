<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Transport\Grpc\GrpcDeadline;
use AutoFlow\CoreBundle\Transport\Grpc\GrpcMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GrpcMetadataTest extends TestCase
{
    public function testItNormalizesAndFiltersMetadata(): void
    {
        self::assertSame(
            ['x-correlation-id' => 'abc-123'],
            GrpcMetadata::allowListed(
                ['X-Correlation-ID' => 'abc-123', 'authorization' => 'Bearer secret'],
                ['x-correlation-id'],
            ),
        );
    }

    public function testItRejectsNewlinesInMetadataValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GrpcMetadata::normalize(['x-test' => "safe\r\nInjected: yes"]);
    }

    public function testItRejectsBinaryMetadataUntilAnExplicitCodecExists(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GrpcMetadata::normalize(['payload-bin' => 'encoded']);
    }

    public function testDeadlineMustBePositiveAndFinite(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GrpcDeadline(0);
    }
}
