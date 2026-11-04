<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\Serialization;

use AutoFlow\CoreBundle\Application\Serialization\SerializerInterface;
use JsonException;
use RuntimeException;

final readonly class JsonSerializer implements SerializerInterface
{
    public function __construct(
        private int $encodeOptions = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        private int $decodeDepth = 512,
    ) {
        if ($this->decodeDepth < 1) {
            throw new RuntimeException('The JSON decode depth must be greater than zero.');
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
        try {
            return json_decode($payload, true, $this->decodeDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode the JSON payload.', previous: $exception);
        }
    }
}
