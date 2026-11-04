<?php

declare(strict_types=1);

namespace App\Grpc\Generated\Pricing\V1;

use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface;

/**
 * RoadRunner service contract mirrored from proto/pricing/v1/pricing.proto.
 * Keep the method name, NAME constant and message types synchronized with the proto.
 */
interface PricingServiceInterface extends ServiceInterface
{
    public const NAME = 'autoflow.pricing.v1.PricingService';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- gRPC method name is defined by the .proto contract.
    public function CalculateQuote(
        ContextInterface $ctx,
        CalculateQuoteRequest $request,
    ): CalculateQuoteResponse;
}
