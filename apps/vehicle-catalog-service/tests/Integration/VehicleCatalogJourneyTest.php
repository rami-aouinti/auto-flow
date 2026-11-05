<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CatalogApplicationService;
use App\Application\Security\CatalogActor;
use App\Infrastructure\Persistence\Doctrine\Entity\ManufacturerRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\VehicleModelRecord;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VehicleCatalogJourneyTest extends KernelTestCase
{
    public function testAdminCanCreatePublishAndRetrieveVehicleThroughApplicationAndDoctrine(): void
    {
        self::bootKernel();

        $catalog = static::getContainer()->get(CatalogApplicationService::class);
        self::assertInstanceOf(CatalogApplicationService::class, $catalog);

        $actor = new CatalogActor('catalog-integration-test', ['catalog:read', 'catalog:manage']);
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $manufacturerId = $catalog->registerManufacturer(
            $actor,
            'TST' . $suffix,
            'AutoFlow Test Manufacturer ' . $suffix,
        );
        $modelId = $catalog->registerModel(
            $actor,
            $manufacturerId,
            'Integration Model ' . $suffix,
        );
        $generationId = $catalog->registerGeneration(
            $actor,
            $modelId,
            'Generation ' . $suffix,
            2024,
            2026,
        );
        $variantId = $catalog->registerVariant($actor, [
            'generationId' => $generationId,
            'name' => 'Integration Electric Variant ' . $suffix,
            'fuelType' => 'electric',
            'powertrain' => 'bev',
            'bodyStyle' => 'suv',
            'transmission' => 'single_speed',
            'powerKw' => 160,
            'seats' => 5,
            'doors' => 5,
            'lengthMm' => 4600,
            'widthMm' => 1850,
            'heightMm' => 1650,
            'co2EmissionGPerKm' => 0,
        ]);

        self::assertTrue($catalog->publishVariant($actor, $variantId));

        $vehicle = $catalog->getVehicle($actor, $variantId);

        self::assertNotNull($vehicle);
        self::assertSame($variantId, $vehicle['variantId']);
        self::assertSame(160, $vehicle['powerKw']);
        self::assertSame('published', $vehicle['status']);
        self::assertIsArray($vehicle['manufacturer']);
        self::assertSame('AutoFlow Test Manufacturer ' . $suffix, $vehicle['manufacturer']['name']);

        $result = $catalog->listVehicles($actor, ['manufacturerId' => $manufacturerId], 10, 0);

        self::assertSame(1, $result['total']);
        self::assertSame($variantId, $result['items'][0]['variantId']);
    }

    public function testDiscontinuedManufacturerCannotReceiveNewGenerationThroughExistingModel(): void
    {
        self::bootKernel();

        $catalog = static::getContainer()->get(CatalogApplicationService::class);
        self::assertInstanceOf(CatalogApplicationService::class, $catalog);
        $actor = new CatalogActor('catalog-integration-test', ['catalog:manage']);
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $manufacturerId = $catalog->registerManufacturer($actor, 'TST' . $suffix, 'Ancestor Test ' . $suffix);
        $modelId = $catalog->registerModel($actor, $manufacturerId, 'Model ' . $suffix);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $manufacturer = $entityManager->find(ManufacturerRecord::class, $manufacturerId);
        self::assertInstanceOf(ManufacturerRecord::class, $manufacturer);
        $manufacturer->setStatus(\App\Domain\ValueObject\CatalogStatus::DISCONTINUED);
        $entityManager->flush();

        $this->expectException(InvalidArgumentException::class);

        $catalog->registerGeneration($actor, $modelId, 'Generation ' . $suffix, 2024, 2026);
    }

    public function testDiscontinuedModelCannotReceiveNewVariantThroughExistingGeneration(): void
    {
        self::bootKernel();

        $catalog = static::getContainer()->get(CatalogApplicationService::class);
        self::assertInstanceOf(CatalogApplicationService::class, $catalog);
        $actor = new CatalogActor('catalog-integration-test', ['catalog:manage']);
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $manufacturerId = $catalog->registerManufacturer($actor, 'TST' . $suffix, 'Variant Ancestor ' . $suffix);
        $modelId = $catalog->registerModel($actor, $manufacturerId, 'Model ' . $suffix);
        $generationId = $catalog->registerGeneration($actor, $modelId, 'Generation ' . $suffix, 2024, 2026);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $model = $entityManager->find(VehicleModelRecord::class, $modelId);
        self::assertInstanceOf(VehicleModelRecord::class, $model);
        $model->setStatus(\App\Domain\ValueObject\CatalogStatus::DISCONTINUED);
        $entityManager->flush();

        $this->expectException(InvalidArgumentException::class);

        $catalog->registerVariant($actor, [
            'generationId' => $generationId,
            'name' => 'Blocked Variant ' . $suffix,
            'fuelType' => 'electric',
            'powertrain' => 'bev',
            'bodyStyle' => 'suv',
            'transmission' => 'single_speed',
        ]);
    }
}
