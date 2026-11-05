<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

use InvalidArgumentException;

use InvalidArgumentException;

final readonly class CreateModelRequest
{
    public function __construct(
        public string $manufacturerId,
        public string $name,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['manufacturerId', 'name'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new InvalidArgumentException(sprintf('%s must be a string.', $field));
            }
        }

        return new self($data['manufacturerId'], $data['name']);
    }
}
