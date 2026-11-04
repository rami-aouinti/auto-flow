<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Health;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final readonly class HealthController
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(private iterable $checks)
    {
    }

    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $results = [];
        $healthy = true;
        $checkCount = 0;

        foreach ($this->checks as $check) {
            ++$checkCount;

            try {
                $result = $check->check();
                $results[$check->name()] = $result->healthy ? 'ok' : 'failed';
                $healthy = $healthy && $result->healthy;
            } catch (\Throwable) {
                $results[$check->name()] = 'failed';
                $healthy = false;
            }
        }

        // Fail closed: an unconfigured service is not ready for traffic.
        $healthy = $healthy && $checkCount > 0;

        return new JsonResponse(
            [
                'status' => $healthy ? 'ok' : 'unavailable',
                'checks' => $results,
            ],
            $healthy ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
