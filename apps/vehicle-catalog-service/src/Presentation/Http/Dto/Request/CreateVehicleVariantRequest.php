<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto\Request;

use InvalidArgumentException;

final readonly class CreateVehicleVariantRequest
{
    /** @param array<string, mixed> $data */
    private function __construct(
        private array $data,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['generationId', 'name', 'fuelType', 'powertrain', 'bodyStyle', 'transmission'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new InvalidArgumentException(sprintf('%s must be a string.', $field));
            }
        }

        if (array_key_exists('manufacturerCode', $data)
            && $data['manufacturerCode'] !== null
            && !is_string($data['manufacturerCode'])) {
            throw new InvalidArgumentException('manufacturerCode must be a string or null.');
        }

        if (array_key_exists('mediaReferences', $data) && !is_array($data['mediaReferences'])) {
            throw new InvalidArgumentException('mediaReferences must be an array.');
        }

        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
