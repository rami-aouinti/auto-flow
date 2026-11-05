<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

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
        $manufacturerId = $data['manufacturerId'] ?? null;
        $name = $data['name'] ?? null;

        if (!is_string($manufacturerId) || !is_string($name)) {
            throw new InvalidArgumentException('Model manufacturerId and name must be strings.');
        }

        return new self($manufacturerId, $name);
    }}
