<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Domain\ValueObject\CatalogStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'catalog_vehicle_variants',
    indexes: [
        new ORM\Index(
            name: 'idx_catalog_variants_facets',
            columns: ['status', 'fuel_type', 'powertrain', 'body_style', 'transmission'],
        ),
        new ORM\Index(name: 'idx_catalog_variants_generation', columns: ['generation_id']),
    ],
)]
class VehicleVariantRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'variant_id', type: 'string', length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: VehicleGenerationRecord::class)]
    #[ORM\JoinColumn(
        name: 'generation_id',
        referencedColumnName: 'generation_id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private VehicleGenerationRecord $generation;

    #[ORM\Column(name: 'manufacturer_code', type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $manufacturerCode;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(name: 'fuel_type', type: 'string', length: 24)]
    private string $fuelType;

    #[ORM\Column(type: 'string', length: 16)]
    private string $powertrain;

    #[ORM\Column(name: 'body_style', type: 'string', length: 20)]
    private string $bodyStyle;

    #[ORM\Column(type: 'string', length: 20)]
    private string $transmission;

    #[ORM\Column(name: 'power_kw', type: 'integer', nullable: true)]
    private ?int $powerKw;

    #[ORM\Column(name: 'displacement_cc', type: 'integer', nullable: true)]
    private ?int $displacementCc;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $seats;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $doors;

    #[ORM\Column(name: 'curb_weight_kg', type: 'integer', nullable: true)]
    private ?int $curbWeightKg;

    #[ORM\Column(name: 'length_mm', type: 'integer', nullable: true)]
    private ?int $lengthMm;

    #[ORM\Column(name: 'width_mm', type: 'integer', nullable: true)]
    private ?int $widthMm;

    #[ORM\Column(name: 'height_mm', type: 'integer', nullable: true)]
    private ?int $heightMm;

    #[ORM\Column(name: 'co2_emission_g_per_km', type: 'integer', nullable: true)]
    private ?int $co2EmissionGPerKm;

    /** @var list<array{mediaId: string, usage: string, sortOrder?: int, altText?: string}> */
    #[ORM\Column(name: 'media_references', type: 'json', options: ['jsonb' => true])]
    private array $mediaReferences;

    #[ORM\Column(type: 'string', length: 16, enumType: CatalogStatus::class)]
    private CatalogStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param list<array{mediaId: string, usage: string, sortOrder?: int, altText?: string}> $mediaReferences
     */
    public function __construct(
        string $id,
        VehicleGenerationRecord $generation,
        ?string $manufacturerCode,
        string $name,
        string $fuelType,
        string $powertrain,
        string $bodyStyle,
        string $transmission,
        ?int $powerKw,
        ?int $displacementCc,
        ?int $seats,
        ?int $doors,
        ?int $curbWeightKg,
        ?int $lengthMm,
        ?int $widthMm,
        ?int $heightMm,
        ?int $co2EmissionGPerKm,
        array $mediaReferences,
        CatalogStatus $status,
    ) {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->id = $id;
        $this->generation = $generation;
        $this->manufacturerCode = $manufacturerCode;
        $this->name = $name;
        $this->fuelType = $fuelType;
        $this->powertrain = $powertrain;
        $this->bodyStyle = $bodyStyle;
        $this->transmission = $transmission;
        $this->powerKw = $powerKw;
        $this->displacementCc = $displacementCc;
        $this->seats = $seats;
        $this->doors = $doors;
        $this->curbWeightKg = $curbWeightKg;
        $this->lengthMm = $lengthMm;
        $this->widthMm = $widthMm;
        $this->heightMm = $heightMm;
        $this->co2EmissionGPerKm = $co2EmissionGPerKm;
        $this->mediaReferences = $mediaReferences;
        $this->status = $status;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }


    public function getId(): string
    {
        return $this->id;
    }

    public function getGeneration(): VehicleGenerationRecord
    {
        return $this->generation;
    }

    public function getManufacturerCode(): ?string
    {
        return $this->manufacturerCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFuelType(): string
    {
        return $this->fuelType;
    }

    public function getPowertrain(): string
    {
        return $this->powertrain;
    }

    public function getBodyStyle(): string
    {
        return $this->bodyStyle;
    }

    public function getTransmission(): string
    {
        return $this->transmission;
    }

    public function getPowerKw(): ?int
    {
        return $this->powerKw;
    }

    public function getDisplacementCc(): ?int
    {
        return $this->displacementCc;
    }

    public function getSeats(): ?int
    {
        return $this->seats;
    }

    public function getDoors(): ?int
    {
        return $this->doors;
    }

    public function getCurbWeightKg(): ?int
    {
        return $this->curbWeightKg;
    }

    public function getLengthMm(): ?int
    {
        return $this->lengthMm;
    }

    public function getWidthMm(): ?int
    {
        return $this->widthMm;
    }

    public function getHeightMm(): ?int
    {
        return $this->heightMm;
    }

    public function getCo2EmissionGPerKm(): ?int
    {
        return $this->co2EmissionGPerKm;
    }

    /** @return list<array{mediaId: string, usage: string, sortOrder?: int, altText?: string}> */
    public function getMediaReferences(): array
    {
        return $this->mediaReferences;
    }


    public function getStatus(): CatalogStatus
    {
        return $this->status;
    }

    public function setStatus(CatalogStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
