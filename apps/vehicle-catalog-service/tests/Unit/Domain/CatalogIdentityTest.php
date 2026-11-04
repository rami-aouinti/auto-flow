<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Entity\Manufacturer;
use App\Domain\Entity\VehicleGeneration;
use App\Domain\Entity\VehicleModel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CatalogIdentityTest extends TestCase
{
    public function testManufacturerCodeIsNormalized(): void
    {
        $manufacturer = Manufacturer::register('bmw', 'BMW');
        self::assertSame('BMW', $manufacturer->code);
        self::assertSame(32, strlen($manufacturer->id->value));
    }

    public function testBlankManufacturerNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Manufacturer::register('BMW', ' ');
    }

    public function testGenerationCannotEndBeforeItStarts(): void
    {
        $model = VehicleModel::create(str_repeat('a', 32), '3 Series');
        $this->expectException(InvalidArgumentException::class);
        VehicleGeneration::create($model->id->value, 'G20', 2024, 2018);
    }
}