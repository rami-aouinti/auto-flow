<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VehicleResponse',
    type: 'object',
    required: [
        'variantId',
        'manufacturer',
        'model',
        'generation',
        'name',
        'fuelType',
        'powertrain',
        'bodyStyle',
        'transmission',
        'status',
    ],
    properties: [
        new OA\Property(property: 'variantId', type: 'string'),
        new OA\Property(property: 'manufacturer', type: 'object', properties: [
            new OA\Property(property: 'manufacturerId', type: 'string'),
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
        ]),
        new OA\Property(property: 'model', type: 'object', properties: [
            new OA\Property(property: 'modelId', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
        ]),
        new OA\Property(property: 'generation', type: 'object', properties: [
            new OA\Property(property: 'generationId', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'productionStartYear', type: 'integer', nullable: true),
            new OA\Property(property: 'productionEndYear', type: 'integer', nullable: true),
        ]),
        new OA\Property(property: 'manufacturerCode', type: 'string', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'fuelType', type: 'string'),
        new OA\Property(property: 'powertrain', type: 'string'),
        new OA\Property(property: 'bodyStyle', type: 'string'),
        new OA\Property(property: 'transmission', type: 'string'),
        new OA\Property(property: 'powerKw', type: 'integer', nullable: true),
        new OA\Property(property: 'displacementCc', type: 'integer', nullable: true),
        new OA\Property(property: 'seats', type: 'integer', nullable: true),
        new OA\Property(property: 'doors', type: 'integer', nullable: true),
        new OA\Property(property: 'curbWeightKg', type: 'integer', nullable: true),
        new OA\Property(property: 'lengthMm', type: 'integer', nullable: true),
        new OA\Property(property: 'widthMm', type: 'integer', nullable: true),
        new OA\Property(property: 'heightMm', type: 'integer', nullable: true),
        new OA\Property(property: 'co2EmissionGPerKm', type: 'integer', nullable: true),
        new OA\Property(property: 'mediaReferences', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'discontinued', 'archived']),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ],
)]

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
