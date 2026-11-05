<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Domain\ValueObject\CatalogStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'catalog_models',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_catalog_model_manufacturer_normalized_name',
            columns: ['manufacturer_id', 'normalized_name'],
        ),
    ],
    indexes: [
        new ORM\Index(name: 'idx_catalog_models_manufacturer', columns: ['manufacturer_id', 'status']),
    ],
)]
class VehicleModelRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'model_id', type: 'string', length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ManufacturerRecord::class)]
    #[ORM\JoinColumn(name: 'manufacturer_id', referencedColumnName: 'manufacturer_id', nullable: false, onDelete: 'RESTRICT')]
    private ManufacturerRecord $manufacturer;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', type: 'string', length: 120)]
    private string $normalizedName;

    #[ORM\Column(type: 'string', length: 16, enumType: CatalogStatus::class)]
    private CatalogStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        ManufacturerRecord $manufacturer,
        string $name,
        string $normalizedName,
        CatalogStatus $status,
    ) {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->id = $id;
        $this->manufacturer = $manufacturer;
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->status = $status;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }


    public function getId(): string
    {
        return $this->id;
    }

    public function getManufacturer(): ManufacturerRecord
    {
        return $this->manufacturer;
    }

    public function getName(): string
    {
        return $this->name;
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
