<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Http\Error;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProblemDetailsFactory
{
    /**
     * Create an RFC 9457-style response without exposing exception internals.
     *
     * @param array<string, mixed> $extensions
     */
    public function create(
        Request $request,
        int $status,
        string $title,
        string $detail,
        array $extensions = [],
    ): JsonResponse {
        $payload = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->getPathInfo(),
        ];

        return new JsonResponse(
            array_merge($payload, $extensions),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
