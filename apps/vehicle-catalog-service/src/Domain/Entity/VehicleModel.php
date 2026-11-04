<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\CatalogueId;
use InvalidArgumentException;

final readonly class VehicleModel
{
    private function __construct(
        public CatalogueId $id,
        public CatalogueId $manufacturerId,
        public string $name,
        public string $normalizedName,
    ) {}

    public static function create(string $manufacturerId, string $name): self
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Model name must contain 1-120 characters.');
        }

        return new self(
            CatalogueId::generate(),
            CatalogueId::fromString($manufacturerId),
            $name,
            mb_strtolower($name),
        );
    }
}