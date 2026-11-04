<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Transport\Grpc;

final class GrpcStatusMapper
{
    public function fromHttpStatus(int $status): GrpcStatusCode
    {
        return match ($status) {
            400, 422 => GrpcStatusCode::INVALID_ARGUMENT,
            401 => GrpcStatusCode::UNAUTHENTICATED,
            403 => GrpcStatusCode::PERMISSION_DENIED,
            404 => GrpcStatusCode::NOT_FOUND,
            405, 501 => GrpcStatusCode::UNIMPLEMENTED,
            409 => GrpcStatusCode::ABORTED,
            412 => GrpcStatusCode::FAILED_PRECONDITION,
            429 => GrpcStatusCode::RESOURCE_EXHAUSTED,
            499 => GrpcStatusCode::CANCELLED,
            504 => GrpcStatusCode::DEADLINE_EXCEEDED,
            503 => GrpcStatusCode::UNAVAILABLE,
            500 => GrpcStatusCode::INTERNAL,
            default => GrpcStatusCode::UNKNOWN,
        };
    }
}
