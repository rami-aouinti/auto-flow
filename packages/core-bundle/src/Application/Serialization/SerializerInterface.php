<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Application\Serialization;

/**
 * Application-facing serialization port.
 *
 * Implementations belong to Infrastructure and must not leak transport
 * libraries into Application or Domain code.
 */
interface SerializerInterface
{
    public function encode(mixed $value): string;

    public function decode(string $payload, ?string $type = null): mixed;
}
