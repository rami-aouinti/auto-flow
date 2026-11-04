<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Transport\Grpc;

final readonly class GrpcError
{
    public function __construct(
        public GrpcStatusCode $status,
        public string $message,
    ) {
    }
}
