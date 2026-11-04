<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Domain\Entity\Manufacturer;
use App\Domain\Entity\VehicleGeneration;
use App\Domain\Entity\VehicleModel;
use App\Domain\Entity\VehicleVariant;

final readonly class CatalogApplicationService
{
    public function __construct(private VehicleCatalogRepositoryInterface $repository) {}

    public function listManufacturers(bool $publishedOnly = true): array
    {
        return $this->repository->listManufacturers($publishedOnly);
    }

    public function listVehicles(array $filters, int $limit, int $offset): array
    {
        return $this->repository->listVehicles($filters, $limit, $offset);
    }

    public function getVehicle(string $id, bool $publishedOnly = true): ?array
    {
        return $this->repository->getVehicle($id, $publishedOnly);
    }

    public function registerManufacturer(string $code, string $name): string
    {
        $manufacturer = Manufacturer::register($code, $name);
        $this->repository->createManufacturer($manufacturer);
        return $manufacturer->id->value;
    }

    public function registerModel(string $manufacturerId, string $name): string
    {
        $model = VehicleModel::create($manufacturerId, $name);
        $this->repository->createModel($model);
        return $model->id->value;
    }

    public function registerGeneration(string $modelId, string $name, ?int $startYear, ?int $endYear): string
    {
        $generation = VehicleGeneration::create($modelId, $name, $startYear, $endYear);
        $this->repository->createGeneration($generation);
        return $generation->id->value;
    }

    public function publishVariant(string $id): bool
    {
        return $this->repository->publishVariant($id);
    }

    public function registerVariant(array $data): string
    {
        $variant = VehicleVariant::register($data);
        $this->repository->createVariant($variant);
        return $variant->id->value;
    }
}