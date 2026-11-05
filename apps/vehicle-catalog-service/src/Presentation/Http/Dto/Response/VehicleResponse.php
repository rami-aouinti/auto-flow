<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Response;

final readonly class VehicleResponse
{
    /** @param array<string, mixed> $data */
    private function __construct(
        private array $data,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
