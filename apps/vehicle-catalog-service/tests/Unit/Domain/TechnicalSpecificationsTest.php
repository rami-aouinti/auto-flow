<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\ValueObject\TechnicalSpecifications;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TechnicalSpecificationsTest extends TestCase
{
    public function testKeepsUnitsExplicitAndAllowsZeroCo2(): void
    {
        $specifications = TechnicalSpecifications::fromArray([
            'powerKw' => 150,
            'displacementCc' => null,
            'lengthMm' => 4500,
            'curbWeightKg' => 2100,
            'co2EmissionGPerKm' => 0,
        ]);

        self::assertSame(150, $specifications->powerKw);
        self::assertSame(4500, $specifications->lengthMm);
        self::assertSame(2100, $specifications->curbWeightKg);
        self::assertSame(0, $specifications->co2EmissionGPerKm);
        self::assertNull($specifications->displacementCc);
    }

    public function testRejectsOutOfRangePower(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TechnicalSpecifications::fromArray(['powerKw' => 10001]);
    }

    public function testRejectsFractionalValuesForIntegerMeasurements(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TechnicalSpecifications::fromArray(['lengthMm' => 4512.5]);
    }
}
