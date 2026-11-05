<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

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
        return new self(
            (string) ($data['manufacturerId'] ?? ''),
            (string) ($data['name'] ?? ''),
        );
    }
}
