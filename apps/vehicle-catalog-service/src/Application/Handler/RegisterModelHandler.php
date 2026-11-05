<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;
use App\Domain\Entity\VehicleModel;

final readonly class RegisterModelHandler
{
    public function __construct(
        private VehicleCatalogRepositoryInterface $repository,
    ) {
    }

    public function __invoke(CatalogActor $actor, string $manufacturerId, string $name): string
    {
        $actor->requireScope('catalog:manage');
        $model = VehicleModel::create($manufacturerId, $name);
        $this->repository->createModel($model);

        return $model->id->value;
    }
}
