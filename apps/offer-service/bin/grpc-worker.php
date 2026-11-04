<?php

declare(strict_types=1);

use AutoFlow\CoreBundle\Infrastructure\RoadRunner\GrpcServerRunner;
use AutoFlow\CoreBundle\Transport\Grpc\Generated\Core\V1\PlatformHealthServiceInterface;
use AutoFlow\CoreBundle\Transport\Grpc\PlatformHealthGrpcService;

require dirname(__DIR__) . '/vendor/autoload.php';

(new GrpcServerRunner())->run([
    PlatformHealthServiceInterface::class => new PlatformHealthGrpcService(),
]);
