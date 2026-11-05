<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;
use App\Domain\Entity\VehicleGeneration;

final readonly class RegisterGenerationHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    public function __invoke(
        CatalogActor $actor,
        string $modelId,
        string $name,
        ?int $startYear,
        ?int $endYear,
    ): string {
        $actor->requireScope('catalog:manage');
        $generation = VehicleGeneration::create($modelId, $name, $startYear, $endYear);
        $this->repository->createGeneration($generation);

        return $generation->id->value;
    }
}
