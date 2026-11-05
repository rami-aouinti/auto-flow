<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;

final readonly class GetVehicleHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function __invoke(CatalogActor $actor, string $variantId): ?array
    {
        $actor->requireScope('catalog:read');

        return $this->repository->getVehicle($variantId);
    }
}
