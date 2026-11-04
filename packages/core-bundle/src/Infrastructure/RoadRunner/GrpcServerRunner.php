<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\RoadRunner;

use InvalidArgumentException;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\Worker;

/**
 * Starts a RoadRunner gRPC server with service-specific generated adapters.
 */
final readonly class GrpcServerRunner
{
    /**
     * @param array<class-string<ServiceInterface>, ServiceInterface> $services
     */
    public function run(array $services): void
    {
        if ($services === []) {
            throw new InvalidArgumentException('At least one generated gRPC service adapter must be registered.');
        }

        $server = new Server(new Invoker(), ['debug' => false]);

        foreach ($services as $serviceInterface => $implementation) {
            $server->registerService($serviceInterface, $implementation);
        }

        $server->serve(Worker::create());
    }
}
