<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;

final readonly class ListManufacturersHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(CatalogActor $actor): array
    {
        $actor->requireScope('catalog:read');

        return $this->repository->listManufacturers();
    }
}
