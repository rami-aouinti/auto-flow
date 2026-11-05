<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Domain\ValueObject\CatalogStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'catalog_generations',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_catalog_generation_model_name',
            columns: ['model_id', 'name'],
        ),
    ],
    indexes: [
        new ORM\Index(name: 'idx_catalog_generations_model', columns: ['model_id', 'status']),
    ],
)]
class VehicleGenerationRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'generation_id', type: 'string', length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: VehicleModelRecord::class)]
    #[ORM\JoinColumn(name: 'model_id', referencedColumnName: 'model_id', nullable: false, onDelete: 'RESTRICT')]
    private VehicleModelRecord $model;

    #[ORM\Column(type: 'string', length: 80)]
    private string $name;

    #[ORM\Column(name: 'production_start_year', type: 'smallint', nullable: true)]
    private ?int $productionStartYear;

    #[ORM\Column(name: 'production_end_year', type: 'smallint', nullable: true)]
    private ?int $productionEndYear;

    #[ORM\Column(type: 'string', length: 16, enumType: CatalogStatus::class)]
    private CatalogStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        VehicleModelRecord $model,
        string $name,
        ?int $productionStartYear,
        ?int $productionEndYear,
        CatalogStatus $status,
    ) {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->id = $id;
        $this->model = $model;
        $this->name = $name;
        $this->productionStartYear = $productionStartYear;
        $this->productionEndYear = $productionEndYear;
        $this->status = $status;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }


    public function getId(): string
    {
        return $this->id;
    }

    public function getModel(): VehicleModelRecord
    {
        return $this->model;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProductionStartYear(): ?int
    {
        return $this->productionStartYear;
    }

    public function getProductionEndYear(): ?int
    {
        return $this->productionEndYear;
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
