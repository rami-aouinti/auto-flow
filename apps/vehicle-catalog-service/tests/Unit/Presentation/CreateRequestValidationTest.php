<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation;

use App\Presentation\Http\Dto\Request\CreateGenerationRequest;
use App\Presentation\Http\Dto\Request\CreateManufacturerRequest;
use App\Presentation\Http\Dto\Request\CreateModelRequest;
use App\Presentation\Http\Dto\Request\CreateVehicleVariantRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreateRequestValidationTest extends TestCase
{
    public function testManufacturerRejectsArrayForName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CreateManufacturerRequest::fromArray(['code' => 'BMW', 'name' => ['BMW']]);
    }

    public function testModelRejectsNonStringManufacturerId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CreateModelRequest::fromArray(['manufacturerId' => 123, 'name' => '3 Series']);
    }

    public function testGenerationRejectsFractionalProductionYear(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CreateGenerationRequest::fromArray([
            'modelId' => str_repeat('a', 32),
            'name' => 'G20',
            'productionStartYear' => 2020.5,
        ]);
    }

    public function testVariantRequiresStringTechnicalVocabularyFields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CreateVehicleVariantRequest::fromArray([
            'generationId' => str_repeat('a', 32),
            'name' => 'Demo',
            'fuelType' => ['electric'],
            'powertrain' => 'bev',
            'bodyStyle' => 'suv',
            'transmission' => 'single_speed',
        ]);
    }
}
