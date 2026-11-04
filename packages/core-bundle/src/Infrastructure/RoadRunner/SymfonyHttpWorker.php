<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\RoadRunner;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Throwable;

/**
 * RoadRunner HTTP adapter for a Symfony HttpKernel.
 */
final readonly class SymfonyHttpWorker
{
    public function run(KernelInterface&TerminableInterface&ResetInterface $kernel): void
    {
        $factory = new Psr17Factory();
        $worker = new PSR7Worker(Worker::create(), $factory, $factory, $factory);
        $requestFactory = new HttpFoundationFactory();
        $responseFactory = new PsrHttpFactory($factory, $factory, $factory, $factory);

        $kernel->boot();

        try {
            while (true) {
                try {
                    $psrRequest = $worker->waitRequest();
                } catch (Throwable $exception) {
                    error_log(sprintf('RoadRunner could not parse an HTTP request (%s).', $exception::class));
                    $worker->respond(new Response(
                        400,
                        ['content-type' => 'application/problem+json'],
                        '{"type":"about:blank","title":"Bad Request","status":400}',
                    ));
                    continue;
                }

                if ($psrRequest === null) {
                    break;
                }

                $request = null;
                $response = null;

                try {
                    $request = $requestFactory->createRequest($psrRequest);
                    $response = $kernel->handle($request);
                    $worker->respond($responseFactory->createResponse($response));
                } catch (Throwable $exception) {
                    error_log(sprintf('RoadRunner HTTP request failed (%s).', $exception::class));
                    $worker->respond(new Response(
                        500,
                        ['content-type' => 'application/problem+json'],
                        '{"type":"about:blank","title":"Internal Server Error","status":500}',
                    ));
                } finally {
                    try {
                        if ($request !== null && $response !== null) {
                            $kernel->terminate($request, $response);
                        }
                    } finally {
                        // Reset tagged stateful services before the next RoadRunner request.
                        $kernel->reset();
                    }
                }
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
