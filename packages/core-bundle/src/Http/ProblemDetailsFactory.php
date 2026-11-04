<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Http;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class ProblemDetailsFactory
{
    private const RESERVED_MEMBERS = ['type', 'title', 'status', 'detail', 'instance'];

    /**
     * @param array<string, mixed> $extensions
     */
    public function create(
        int $status,
        string $title,
        ?string $detail = null,
        string $type = 'about:blank',
        ?string $instance = null,
        array $extensions = [],
    ): JsonResponse {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('Problem Details status must be an HTTP error status (400-599).');
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('Problem Details title must not be empty.');
        }

        if ($type !== 'about:blank' && filter_var($type, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Problem Details type must be an absolute URI or about:blank.');
        }

        $payload = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null) {
            $payload['detail'] = $detail;
        }

        if ($instance !== null) {
            $payload['instance'] = $instance;
        }

        foreach ($extensions as $name => $value) {
            if (!is_string($name) || $name === '' || in_array($name, self::RESERVED_MEMBERS, true)) {
                throw new InvalidArgumentException(
                    'Problem Details extension members must use non-reserved string names.',
                );
            }

            $payload[$name] = $value;
        }

        $response = new JsonResponse($payload, $status);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
