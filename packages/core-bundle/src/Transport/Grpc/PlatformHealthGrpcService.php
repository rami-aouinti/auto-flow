<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Transport\Grpc;

use AutoFlow\CoreBundle\Transport\Grpc\Generated\Core\V1\PlatformHealthServiceInterface;
use Google\Protobuf\GPBEmpty;
use Spiral\RoadRunner\GRPC\ContextInterface;

/**
 * Protocol-level smoke check. Application readiness remains an HTTP health
 * check and service-specific business RPCs are implemented by each service.
 */
final class PlatformHealthGrpcService implements PlatformHealthServiceInterface
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- gRPC method name is defined by the .proto contract.
    public function Ping(ContextInterface $ctx, GPBEmpty $requestMessage): GPBEmpty
    {
        return new GPBEmpty();
    }
}
