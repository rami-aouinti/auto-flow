<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Handler\GetVehicleHandler;
use App\Application\Handler\ListManufacturersHandler;
use App\Application\Handler\ListVehiclesHandler;
use App\Application\Handler\PublishVehicleVariantHandler;
use App\Application\Handler\RegisterGenerationHandler;
use App\Application\Handler\RegisterManufacturerHandler;
use App\Application\Handler\RegisterModelHandler;
use App\Application\Handler\RegisterVehicleVariantHandler;
use App\Application\Security\CatalogActor;

final readonly class CatalogApplicationService
{
    public function __construct(
        private ListManufacturersHandler $listManufacturers,
        private ListVehiclesHandler $listVehicles,
        private GetVehicleHandler $getVehicle,
        private RegisterManufacturerHandler $registerManufacturer,
        private RegisterModelHandler $registerModel,
        private RegisterGenerationHandler $registerGeneration,
        private RegisterVehicleVariantHandler $registerVariantHandler,
        private PublishVehicleVariantHandler $publishVehicleVariant,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listManufacturers(CatalogActor $actor): array
    {
        return ($this->listManufacturers)($actor);
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listVehicles(
        CatalogActor $actor,
        array $filters,
        int $limit,
        int $offset,
    ): array {
        return ($this->listVehicles)($actor, $filters, $limit, $offset);
    }

    /** @return array<string, mixed>|null */
    public function getVehicle(CatalogActor $actor, string $id): ?array
    {
        return ($this->getVehicle)($actor, $id);
    }

    public function registerManufacturer(CatalogActor $actor, string $code, string $name): string
    {
        return ($this->registerManufacturer)($actor, $code, $name);
    }

    public function registerModel(CatalogActor $actor, string $manufacturerId, string $name): string
    {
        return ($this->registerModel)($actor, $manufacturerId, $name);
    }

    public function registerGeneration(
        CatalogActor $actor,
        string $modelId,
        string $name,
        ?int $startYear,
        ?int $endYear,
    ): string {
        return ($this->registerGeneration)($actor, $modelId, $name, $startYear, $endYear);
    }

    /** @param array<string, mixed> $data */
    public function registerVariant(CatalogActor $actor, array $data): string
    {
        return ($this->registerVariantHandler)($actor, $data);
    }

    public function publishVariant(CatalogActor $actor, string $id): bool
    {
        return ($this->publishVehicleVariant)($actor, $id);
    }
}
