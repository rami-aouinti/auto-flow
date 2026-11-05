<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineVehicleCatalogRepositoryTest extends KernelTestCase
{
    public function testDoctrineRepositoryReadsPublishedFixtureVariants(): void
    {
        self::bootKernel();

        $repository = static::getContainer()->get(VehicleCatalogRepositoryInterface::class);
        self::assertInstanceOf(VehicleCatalogRepositoryInterface::class, $repository);

        $result = $repository->listVehicles([], 20, 0);

        self::assertGreaterThanOrEqual(7, $result['total']);
        self::assertNotEmpty($result['items']);
        self::assertSame('published', $result['items'][0]['status']);
    }

    public function testDoctrineRepositoryLoadsVehicleDetailByOpaqueId(): void
    {
        self::bootKernel();

        $repository = static::getContainer()->get(VehicleCatalogRepositoryInterface::class);
        self::assertInstanceOf(VehicleCatalogRepositoryInterface::class, $repository);

        $vehicle = $repository->getVehicle('40000000000000000000000000000001');

        self::assertNotNull($vehicle);
        self::assertSame('Golf 2.0 TDI 110 kW', $vehicle['name']);
        self::assertSame(110, $vehicle['powerKw']);
        self::assertSame('Volkswagen', $vehicle['manufacturer']['name']);
    }
}
