<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;

final readonly class ListVehiclesHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function __invoke(CatalogActor $actor, array $filters, int $limit, int $offset): array
    {
        $actor->requireScope('catalog:read');

        return $this->repository->listVehicles($filters, $limit, $offset);
    }
}
