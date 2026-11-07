<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\CatalogStatus;
use App\Domain\ValueObject\CatalogueId;
use InvalidArgumentException;

final readonly class VehicleGeneration
{
    private function __construct(
        public CatalogueId $id,
        public CatalogueId $modelId,
        public string $name,
        public ?int $productionStartYear,
        public ?int $productionEndYear,
        public CatalogStatus $status,
    ) {
    }

    public static function create(
        string $modelId,
        string $name,
        ?int $startYear,
        ?int $endYear,
    ): self {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Generation name must contain 1-80 characters.');
        }

        foreach ([$startYear, $endYear] as $year) {
            if ($year !== null && ($year < 1886 || $year > 2100)) {
                throw new InvalidArgumentException('Production years are outside the supported range.');
            }
        }

        if ($startYear !== null && $endYear !== null && $endYear < $startYear) {
            throw new InvalidArgumentException('Production end year cannot precede start year.');
        }

        return new self(
            CatalogueId::generate(),
            CatalogueId::fromString($modelId),
            $name,
            $startYear,
            $endYear,
            CatalogStatus::DRAFT,
        );
    }

    public function withStatus(CatalogStatus $status): self
    {
        return new self(
            $this->id,
            $this->modelId,
            $this->name,
            $this->productionStartYear,
            $this->productionEndYear,
            $this->status->transitionTo($status),
        );
    }

    public function equals(self $other): bool
    {
        return $this->id->value === $other->id->value;
    }
}
