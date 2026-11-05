<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;
use App\Domain\Entity\VehicleVariant;

final readonly class RegisterVehicleVariantHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function __invoke(CatalogActor $actor, array $data): string
    {
        $actor->requireScope('catalog:manage');
        $variant = VehicleVariant::register($data);
        $this->repository->createVariant($variant);

        return $variant->id->value;
    }
}
