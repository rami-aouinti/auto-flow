<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Entity\VehicleVariant;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VehicleVariantTest extends TestCase
{
    public function testRegistersVariantWithExplicitTechnicalUnits(): void
    {
        $variant = VehicleVariant::register([
            'generationId' => str_repeat('a', 32),
            'name' => 'Demo EV',
            'fuelType' => 'electric',
            'powertrain' => 'bev',
            'bodyStyle' => 'suv',
            'transmission' => 'single_speed',
            'powerKw' => 150,
            'seats' => 5,
            'doors' => 5,
            'lengthMm' => 4500,
            'widthMm' => 1800,
            'heightMm' => 1600,
            'co2EmissionGPerKm' => 0,
        ]);

        self::assertSame(150, $variant->powerKw);
        self::assertSame(4500, $variant->lengthMm);
        self::assertSame(0, $variant->co2EmissionGPerKm);
        self::assertSame('electric', $variant->fuelType);
    }

    public function testRejectsUnknownFuelType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VehicleVariant::register([
            'generationId' => str_repeat('a', 32),
            'name' => 'Bad',
            'fuelType' => 'diesel-electric',
            'powertrain' => 'bev',
            'bodyStyle' => 'suv',
            'transmission' => 'automatic',
        ]);
    }

    public function testRejectsInvalidDimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VehicleVariant::register([
            'generationId' => str_repeat('a', 32),
            'name' => 'Bad',
            'fuelType' => 'electric',
            'powertrain' => 'bev',
            'bodyStyle' => 'suv',
            'transmission' => 'automatic',
            'lengthMm' => -20,
        ]);
    }
}
