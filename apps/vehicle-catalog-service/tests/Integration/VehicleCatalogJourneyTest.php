<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CatalogApplicationService;
use App\Application\Security\CatalogActor;
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
        self::assertSame('AutoFlow Test Manufacturer ' . $suffix, $vehicle['manufacturer']['name']);

        $result = $catalog->listVehicles($actor, ['manufacturerId' => $manufacturerId], 10, 0);

        self::assertSame(1, $result['total']);
        self::assertSame($variantId, $result['items'][0]['variantId']);
    }
}
