<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\RoadRunner;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * RoadRunner HTTP adapter for a Symfony HttpKernel.
 *
 * A request receives at most one response attempt. A failed response write
 * terminates this worker loop because the transport may no longer be usable.
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

                    if (!$this->respond($worker, $this->problemResponse(400, 'Bad Request'))) {
                        break;
                    }

                    continue;
                }

                if ($psrRequest === null) {
                    break;
                }

                $request = null;
                $response = new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);

                try {
                    $request = $requestFactory->createRequest($psrRequest);
                    $response = $kernel->handle($request);
                } catch (Throwable $exception) {
                    error_log(sprintf('RoadRunner HTTP request failed (%s).', $exception::class));
                    $response = $this->problemResponse(500, 'Internal Server Error');
                }

                try {
                    $psrResponse = $responseFactory->createResponse($response);

                    if (!$this->respond($worker, $psrResponse)) {
                        break;
                    }
                } catch (Throwable $exception) {
                    // Never attempt a second response after conversion or transport failure.
                    error_log(sprintf('RoadRunner could not write an HTTP response (%s).', $exception::class));
                    break;
                } finally {
                    try {
                        if ($request instanceof Request) {
                            $kernel->terminate($request, $response);
                        }
                    } finally {
                        $kernel->reset();
                    }
                }
            }
        } finally {
            $kernel->shutdown();
        }
    }

    private function respond(PSR7Worker $worker, PsrResponse $response): bool
    {
        try {
            $worker->respond($response);

            return true;
        } catch (Throwable $exception) {
            error_log(sprintf('RoadRunner response transport failed (%s).', $exception::class));

            return false;
        }
    }

    private function problemResponse(int $status, string $title): Response
    {
        return new Response(
            json_encode(
                [
                    'type' => 'about:blank',
                    'title' => $title,
                    'status' => $status,
                ],
                JSON_THROW_ON_ERROR,
            ),
            $status,
            ['content-type' => 'application/problem+json'],
        );
    }
}
