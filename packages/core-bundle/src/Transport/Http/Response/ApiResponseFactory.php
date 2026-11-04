<?php

declare(strict_types=1);

namespace AutoFlow\\CoreBundle\\Transport\\Http\\Response;

use Symfony\\Component\\HttpFoundation\\JsonResponse;

final class ApiResponseFactory
{
    /**
     * Build a consistent JSON response without imposing an application DTO.
     *
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, mixed> $meta
     */
    public function success(
        array $data,
        int $status = JsonResponse::HTTP_OK,
        array $meta = [],
        array $headers = [],
    ): JsonResponse {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status, $headers);
    }
}
