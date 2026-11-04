<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Transport\Grpc;

use InvalidArgumentException;

/**
 * Validates metadata before it crosses an internal gRPC boundary.
 * Metadata keys are normalized to lowercase as required by gRPC.
 */
final class GrpcMetadata
{
    /**
     * @param array<array-key, mixed> $metadata
     * @return array<string, string|list<string>>
     */
    public function normalize(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = $this->normalizeKey($key);

            if (array_key_exists($normalizedKey, $normalized)) {
                throw new InvalidArgumentException('Duplicate gRPC metadata keys are not allowed.');
            }

            $normalized[$normalizedKey] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * Copy only explicitly allowed operational metadata.
     *
     * @param array<array-key, mixed> $metadata
     * @param list<string> $allowedKeys
     * @return array<string, string|list<string>>
     */
    public function allowListed(array $metadata, array $allowedKeys): array
    {
        $normalized = $this->normalize($metadata);
        $allowed = [];

        foreach ($allowedKeys as $allowedKey) {
            $allowed[$this->normalizeKey($allowedKey)] = true;
        }

        return array_intersect_key($normalized, $allowed);
    }

    private function normalizeKey(mixed $key): string
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException('gRPC metadata keys must be strings.');
        }

        $key = strtolower($key);

        if (
            preg_match('/^[0-9a-z_.-]+$/D', $key) !== 1
            || str_starts_with($key, 'grpc-')
            || str_ends_with($key, '-bin')
        ) {
            throw new InvalidArgumentException('Invalid or unsupported gRPC metadata key.');
        }

        return $key;
    }

    /**
     * @return string|list<string>
     */
    private function normalizeValue(mixed $value): string|array
    {
        if (is_string($value)) {
            $values = [$value];
        } elseif (is_array($value) && array_is_list($value)) {
            $values = $value;
        } else {
            throw new InvalidArgumentException('gRPC metadata values must be strings or lists of strings.');
        }

        foreach ($values as $item) {
            if (!is_string($item) || preg_match('/^[\x20-\x7E]*$/D', $item) !== 1) {
                throw new InvalidArgumentException('gRPC metadata values must contain printable ASCII only.');
            }
        }

        return $value;
    }
}
