<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Transport\Http;

use AutoFlow\CoreBundle\Transport\Grpc\GrpcStatusCode;

final class HttpStatusMapper
{
    public function fromGrpcStatus(GrpcStatusCode $status): int
    {
        return match ($status) {
            GrpcStatusCode::OK => 200,
            GrpcStatusCode::CANCELLED => 499,
            GrpcStatusCode::INVALID_ARGUMENT, GrpcStatusCode::FAILED_PRECONDITION,
            GrpcStatusCode::OUT_OF_RANGE => 400,
            GrpcStatusCode::DEADLINE_EXCEEDED => 504,
            GrpcStatusCode::NOT_FOUND => 404,
            GrpcStatusCode::ALREADY_EXISTS, GrpcStatusCode::ABORTED => 409,
            GrpcStatusCode::PERMISSION_DENIED => 403,
            GrpcStatusCode::RESOURCE_EXHAUSTED => 429,
            GrpcStatusCode::UNAUTHENTICATED => 401,
            GrpcStatusCode::UNAVAILABLE => 503,
            GrpcStatusCode::UNIMPLEMENTED => 501,
            GrpcStatusCode::UNKNOWN, GrpcStatusCode::INTERNAL, GrpcStatusCode::DATA_LOSS => 500,
        };
    }
}
