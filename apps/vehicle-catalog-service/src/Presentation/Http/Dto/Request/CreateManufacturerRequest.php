<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

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
        return new self(
            (string) ($data['code'] ?? ''),
            (string) ($data['name'] ?? ''),
        );
    }
}
