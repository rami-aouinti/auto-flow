<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;

final readonly class PublishVehicleVariantHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    public function __invoke(CatalogActor $actor, string $variantId): bool
    {
        $actor->requireScope('catalog:manage');

        return $this->repository->publishVariant($variantId);
    }
}
