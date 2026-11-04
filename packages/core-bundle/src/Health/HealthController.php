<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Health;

use Symfony\Component\HttpFoundation\JsonResponse;

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

        foreach ($this->checks as $check) {
            try {
                $result = $check->check();
                $results[$check->name()] = [
                    'status' => $result->healthy ? 'ok' : 'failed',
                    'details' => $result->details,
                ];
                $healthy = $healthy && $result->healthy;
            } catch (\Throwable) {
                $results[$check->name()] = ['status' => 'failed'];
                $healthy = false;
            }
        }

        return new JsonResponse(
            ['status' => $healthy ? 'ok' : 'unavailable', 'checks' => $results],
            $healthy ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
