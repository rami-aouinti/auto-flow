<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Transport\Grpc\GrpcStatusCode;
use AutoFlow\CoreBundle\Transport\Grpc\GrpcStatusMapper;
use AutoFlow\CoreBundle\Transport\Http\HttpStatusMapper;
use PHPUnit\Framework\TestCase;

final class GrpcStatusMapperTest extends TestCase
{
    public function testHttpErrorsMapToGrpcStatusCodes(): void
    {
        $mapper = new GrpcStatusMapper();

        self::assertSame(GrpcStatusCode::INVALID_ARGUMENT, $mapper->fromHttpStatus(422));
        self::assertSame(GrpcStatusCode::NOT_FOUND, $mapper->fromHttpStatus(404));
        self::assertSame(GrpcStatusCode::UNAVAILABLE, $mapper->fromHttpStatus(503));
        self::assertSame(GrpcStatusCode::INTERNAL, $mapper->fromHttpStatus(500));
    }

    public function testGrpcStatusMapsToHttpStatus(): void
    {
        $mapper = new HttpStatusMapper();

        self::assertSame(400, $mapper->fromGrpcStatus(GrpcStatusCode::INVALID_ARGUMENT));
        self::assertSame(404, $mapper->fromGrpcStatus(GrpcStatusCode::NOT_FOUND));
        self::assertSame(503, $mapper->fromGrpcStatus(GrpcStatusCode::UNAVAILABLE));
        self::assertSame(500, $mapper->fromGrpcStatus(GrpcStatusCode::DATA_LOSS));
    }
}
