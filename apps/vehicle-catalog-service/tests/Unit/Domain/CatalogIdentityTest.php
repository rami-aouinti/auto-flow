<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Entity\Manufacturer;
use App\Domain\Entity\VehicleGeneration;
use App\Domain\Entity\VehicleModel;
use App\Domain\ValueObject\CatalogueId;
use App\Domain\ValueObject\CatalogStatus;
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

    public function testManufacturerEqualityUsesCanonicalIdentity(): void
    {
        $manufacturer = Manufacturer::register('bmw', 'BMW');
        $reconstituted = Manufacturer::reconstitute(
            CatalogueId::fromString($manufacturer->id->value),
            'BMW',
            'BMW',
            CatalogStatus::DRAFT,
        );

        self::assertTrue($manufacturer->equals($reconstituted));
    }

    public function testArchivedStatusIsTerminal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogStatus::ARCHIVED->transitionTo(CatalogStatus::PUBLISHED);
    }

    public function testPublishedStatusCanBeDiscontinued(): void
    {
        self::assertSame(
            CatalogStatus::DISCONTINUED,
            CatalogStatus::PUBLISHED->transitionTo(CatalogStatus::DISCONTINUED),
        );
    }

    public function testOnlyDraftAndPublishedParentsCanReceiveChildren(): void
    {
        self::assertTrue(CatalogStatus::DRAFT->canAcceptChildren());
        self::assertTrue(CatalogStatus::PUBLISHED->canAcceptChildren());
        self::assertFalse(CatalogStatus::DISCONTINUED->canAcceptChildren());
        self::assertFalse(CatalogStatus::ARCHIVED->canAcceptChildren());
    }
}
