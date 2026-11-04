<?php

declare(strict_types=1);

namespace App\\Domain\\ValueObject;

use InvalidArgumentException;

final readonly class CatalogueId
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Catalogue identifiers must be 32 lowercase hexadecimal characters.');
        }

        return new self($value);
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }
}