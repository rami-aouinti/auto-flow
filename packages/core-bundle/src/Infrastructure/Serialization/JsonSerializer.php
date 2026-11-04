<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\Serialization;

use AutoFlow\CoreBundle\Application\Serialization\SerializerInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * JSON wire-format serializer.
 *
 * JSON decoding returns native PHP values (arrays/scalars/null). Typed object
 * hydration belongs to an explicit DTO mapper or Symfony Serializer adapter;
 * silently ignoring the requested type would be unsafe and misleading.
 */
final readonly class JsonSerializer implements SerializerInterface
{
    public function __construct(
        private int $encodeOptions = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        private int $decodeDepth = 512,
    ) {
        if ($this->decodeDepth < 1) {
            throw new InvalidArgumentException('The JSON decode depth must be greater than zero.');
        }
    }

    public function encode(mixed $value): string
    {
        try {
            $encoded = json_encode($value, $this->encodeOptions);

            if (!is_string($encoded)) {
                throw new RuntimeException('JSON encoding did not produce a string.');
            }

            return $encoded;
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the value as JSON.', previous: $exception);
        }
    }

    public function decode(string $payload, ?string $type = null): mixed
    {
        if ($type !== null) {
            throw new InvalidArgumentException(
                'JsonSerializer does not hydrate PHP classes. Decode to native values and use an explicit DTO mapper.',
            );
        }

        try {
            return json_decode($payload, true, $this->decodeDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode the JSON payload.', previous: $exception);
        }
    }
}
