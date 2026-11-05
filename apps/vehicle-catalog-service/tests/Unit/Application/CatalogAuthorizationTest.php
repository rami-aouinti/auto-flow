<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Exception\CatalogAccessDeniedException;
use App\Application\Handler\ListVehiclesHandler;
use App\Application\Handler\RegisterManufacturerHandler;
use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Application\Security\CatalogActor;
use PHPUnit\Framework\TestCase;

final class CatalogAuthorizationTest extends TestCase
{
    public function testReadHandlerRejectsActorWithoutReadScope(): void
    {
        $repository = $this->createMock(VehicleCatalogRepositoryInterface::class);
        $handler = new ListVehiclesHandler($repository);
        $actor = new CatalogActor('customer-123', ['catalog:manage']);

        $this->expectException(CatalogAccessDeniedException::class);

        $handler($actor, [], 20, 0);
    }

    public function testWriteHandlerRejectsActorWithoutManageScope(): void
    {
        $repository = $this->createMock(VehicleCatalogRepositoryInterface::class);
        $handler = new RegisterManufacturerHandler($repository);
        $actor = new CatalogActor('customer-123', ['catalog:read']);

        $this->expectException(CatalogAccessDeniedException::class);

        $handler($actor, 'BMW', 'BMW');
    }
}
