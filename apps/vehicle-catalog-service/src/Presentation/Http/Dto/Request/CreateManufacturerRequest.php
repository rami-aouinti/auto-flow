<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

use InvalidArgumentException;

final readonly class CreateManufacturerRequest
{
    public function __construct(
        public string $code,
        public string $name,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $code = $data['code'] ?? null;
        $name = $data['name'] ?? null;

        if (!is_string($code) || !is_string($name)) {
            throw new InvalidArgumentException('Manufacturer code and name must be strings.');
        }

        return new self($code, $name);
    }}
