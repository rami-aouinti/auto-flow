<?php

declare(strict_types=1);

namespace AutoFlow\\CoreBundle\\Transport\\Grpc;

use InvalidArgumentException;

/**
 * Validates metadata before it crosses an internal gRPC boundary.
 * Metadata keys are normalized to lowercase as required by gRPC.
 */
final class GrpcMetadata
{
    /**
     * @param array<string, string|list<string>> $metadata
     * @return array<string, string|list<string>>
     */
    public static function normalize(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            $key = strtolower(trim($key));

            if (preg_match('/^[0-9a-z_.-]+$/D', $key) !== 1 || str_ends_with($key, '-bin')) {
                throw new InvalidArgumentException('Invalid or unsupported gRPC metadata key.');
            }

            if (!is_string($value) && !(is_array($value) && array_is_list($value))) {
                throw new InvalidArgumentException('gRPC metadata values must be strings.');
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                if (!is_string($item) || str_contains($item, "\\r") || str_contains($item, "\\n")) {
                    throw new InvalidArgumentException('Invalid gRPC metadata value.');
                }
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Copy only explicitly allowed operational metadata.
     *
     * @param array<string, string|list<string>> $metadata
     * @param list<string> $allowedKeys
     * @return array<string, string|list<string>>
     */
    public static function allowListed(array $metadata, array $allowedKeys): array
    {
        $normalized = self::normalize($metadata);
        $allowed = array_fill_keys(array_map('strtolower', $allowedKeys), true);

        return array_intersect_key($normalized, $allowed);
    }
}
