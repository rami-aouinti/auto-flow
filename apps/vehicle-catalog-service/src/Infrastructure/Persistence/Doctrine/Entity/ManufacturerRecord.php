<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Entity;

use App\Domain\ValueObject\CatalogStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_manufacturers')]
class ManufacturerRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'manufacturer_id', type: 'string', length: 32)]
    private string $id;

    #[ORM\Column(type: 'string', length: 40, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'string', length: 16, enumType: CatalogStatus::class)]
    private CatalogStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $code, string $name, CatalogStatus $status)
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->status = $status;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getStatus(): CatalogStatus { return $this->status; }
    public function setStatus(CatalogStatus $status): void { $this->status = $status; $this->touch(); }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
