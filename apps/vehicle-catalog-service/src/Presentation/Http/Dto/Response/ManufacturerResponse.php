<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ManufacturerResponse',
    type: 'object',
    required: ['manufacturerId', 'code', 'name', 'status', 'createdAt', 'updatedAt'],
    properties: [
        new OA\Property(property: 'manufacturerId', type: 'string'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'discontinued', 'archived']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ],
)]

final readonly class ManufacturerResponse
{
    public function __construct(
        public string $manufacturerId,
        public string $code,
        public string $name,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['manufacturerId'],
            (string) $data['code'],
            (string) $data['name'],
            (string) $data['status'],
            (string) $data['createdAt'],
            (string) $data['updatedAt'],
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'manufacturerId' => $this->manufacturerId,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
