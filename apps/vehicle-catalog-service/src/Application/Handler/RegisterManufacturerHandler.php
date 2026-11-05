<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;
use App\Domain\Entity\Manufacturer;

final readonly class RegisterManufacturerHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    public function __invoke(CatalogActor $actor, string $code, string $name): string
    {
        $actor->requireScope('catalog:manage');
        $manufacturer = Manufacturer::register($code, $name);
        $this->repository->createManufacturer($manufacturer);

        return $manufacturer->id->value;
    }
}
