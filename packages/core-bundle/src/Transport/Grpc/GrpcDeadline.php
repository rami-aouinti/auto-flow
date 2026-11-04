<?php

declare(strict_types=1);

namespace AutoFlow\\CoreBundle\\Transport\\Grpc;

use InvalidArgumentException;

/**
 * Small transport-neutral representation of a client-side RPC deadline.
 * Adapters convert this duration to the concrete gRPC client's deadline API.
 */
final readonly class GrpcDeadline
{
    public function __construct(public float $timeoutSeconds)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new InvalidArgumentException('gRPC deadline must be a positive finite duration.');
        }
    }
}
