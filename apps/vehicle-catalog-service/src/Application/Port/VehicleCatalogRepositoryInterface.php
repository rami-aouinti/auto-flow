<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Entity\Manufacturer;
use App\Domain\Entity\VehicleGeneration;
use App\Domain\Entity\VehicleModel;
use App\Domain\Entity\VehicleVariant;

interface VehicleCatalogRepositoryInterface
{
    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function listVehicles(array $filters, int $limit, int $offset): array;
    /** @return array<string,mixed>|null */
    public function getVehicle(string $variantId, bool $publishedOnly = true): ?array;
    /** @return list<array<string,mixed>> */
    public function listManufacturers(bool $publishedOnly = true): array;
    public function createManufacturer(Manufacturer $manufacturer): void;
    public function createModel(VehicleModel $model): void;
    public function createGeneration(VehicleGeneration $generation): void;
    public function createVariant(VehicleVariant $variant): void;
    public function publishVariant(string $variantId): bool;
}