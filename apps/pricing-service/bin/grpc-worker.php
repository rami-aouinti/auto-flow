<?php

declare(strict_types=1);

use App\Grpc\Generated\Pricing\V1\PricingServiceInterface;
use App\Grpc\PricingGrpcService;
use App\Kernel;
use AutoFlow\CoreBundle\Infrastructure\RoadRunner\GrpcServerRunner;
use AutoFlow\CoreBundle\Transport\Grpc\Generated\Core\V1\PlatformHealthServiceInterface;
use AutoFlow\CoreBundle\Transport\Grpc\PlatformHealthGrpcService;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel(
    $_SERVER['APP_ENV'] ?? 'prod',
    filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
);
$kernel->boot();

try {
    $pricingGrpcService = $kernel->getContainer()->get(PricingGrpcService::class);

    (new GrpcServerRunner())->run([
        PlatformHealthServiceInterface::class => new PlatformHealthGrpcService(),
        PricingServiceInterface::class => $pricingGrpcService,
    ]);
} finally {
    $kernel->shutdown();
}
