<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Domain\Entity\Manufacturer;
use App\Domain\Entity\VehicleGeneration;
use App\Domain\Entity\VehicleModel;
use App\Domain\Entity\VehicleVariant;
use App\Domain\ValueObject\CatalogStatus;
use App\Infrastructure\Persistence\Doctrine\Entity\CatalogOutboxRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\ManufacturerRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\VehicleGenerationRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\VehicleModelRecord;
use App\Infrastructure\Persistence\Doctrine\Entity\VehicleVariantRecord;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class DoctrineVehicleCatalogRepository implements VehicleCatalogRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listVehicles(array $filters, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new InvalidArgumentException('Limit must be 1-100 and offset must be non-negative.');
        }

        $queryBuilder = $this->createPublishedVehicleQueryBuilder();
        $this->applyFilters($queryBuilder, $filters);
        $countQueryBuilder = $this->createPublishedVehicleQueryBuilder();
        $this->applyFilters($countQueryBuilder, $filters);
        $total = (int) $countQueryBuilder
            ->select('COUNT(v.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<VehicleVariantRecord> $records */
        $records = $queryBuilder
            ->orderBy('mf.name', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->addOrderBy('g.productionStartYear', 'DESC')
            ->addOrderBy('v.name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return [
            'items' => array_map($this->mapVehicle(...), $records),
            'total' => $total,
        ];
    }

    public function getVehicle(string $variantId, bool $publishedOnly = true): ?array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('v', 'g', 'm', 'mf')
            ->from(VehicleVariantRecord::class, 'v')
            ->join('v.generation', 'g')
            ->join('g.model', 'm')
            ->join('m.manufacturer', 'mf')
            ->where('v.id = :id')
            ->setParameter('id', $variantId);

        if ($publishedOnly) {
            $queryBuilder
                ->andWhere('v.status = :published')
                ->andWhere('g.status = :published')
                ->andWhere('m.status = :published')
                ->andWhere('mf.status = :published')
                ->setParameter('published', CatalogStatus::PUBLISHED->value);
        }

        /** @var VehicleVariantRecord|null $record */
        $record = $queryBuilder->getQuery()->getOneOrNullResult();

        return $record === null ? null : $this->mapVehicle($record);
    }

    /** @return list<array<string, mixed>> */
    public function listManufacturers(bool $publishedOnly = true): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(ManufacturerRecord::class, 'm')
            ->orderBy('m.name', 'ASC');

        if ($publishedOnly) {
            $queryBuilder
                ->where('m.status = :published')
                ->setParameter('published', CatalogStatus::PUBLISHED);
        }

        /** @var list<ManufacturerRecord> $records */
        $records = $queryBuilder->getQuery()->getResult();

        return array_map(
            static fn (ManufacturerRecord $manufacturer): array => [
                'manufacturerId' => $manufacturer->getId(),
                'code' => $manufacturer->getCode(),
                'name' => $manufacturer->getName(),
                'status' => $manufacturer->getStatus()->value,
                'createdAt' => $manufacturer->getCreatedAt()->format(DATE_ATOM),
                'updatedAt' => $manufacturer->getUpdatedAt()->format(DATE_ATOM),
            ],
            $records,
        );
    }

    public function createManufacturer(Manufacturer $manufacturer): void
    {
        $record = new ManufacturerRecord(
            $manufacturer->id->value,
            $manufacturer->code,
            $manufacturer->name,
            $manufacturer->status,
        );

        $this->persistWithEvent(
            $record,
            'catalog.manufacturer.created',
            $manufacturer->id->value,
            [
                'manufacturerId' => $manufacturer->id->value,
                'code' => $manufacturer->code,
                'name' => $manufacturer->name,
                'status' => $manufacturer->status->value,
            ],
        );
    }

    public function createModel(VehicleModel $model): void
    {
        $manufacturer = $this->entityManager->find(
            ManufacturerRecord::class,
            $model->manufacturerId->value,
        );

        if (!$manufacturer instanceof ManufacturerRecord) {
            throw new InvalidArgumentException('The referenced manufacturer does not exist.');
        }

        if (!$manufacturer->getStatus()->canAcceptChildren()) {
            throw new InvalidArgumentException('A discontinued or archived manufacturer cannot receive new models.');
        }

        $record = new VehicleModelRecord(
            $model->id->value,
            $manufacturer,
            $model->name,
            $model->normalizedName,
            $model->status,
        );

        $this->persistWithEvent(
            $record,
            'catalog.model.created',
            $model->id->value,
            [
                'modelId' => $model->id->value,
                'manufacturerId' => $model->manufacturerId->value,
                'name' => $model->name,
            ],
        );
    }

    public function createGeneration(VehicleGeneration $generation): void
    {
        $model = $this->entityManager->find(
            VehicleModelRecord::class,
            $generation->modelId->value,
        );

        if (!$model instanceof VehicleModelRecord) {
            throw new InvalidArgumentException('The referenced model does not exist.');
        }

        if (!$model->getStatus()->canAcceptChildren()) {
            throw new InvalidArgumentException('A discontinued or archived model cannot receive new generations.');
        }

        $record = new VehicleGenerationRecord(
            $generation->id->value,
            $model,
            $generation->name,
            $generation->productionStartYear,
            $generation->productionEndYear,
            $generation->status,
        );

        $this->persistWithEvent(
            $record,
            'catalog.generation.created',
            $generation->id->value,
            [
                'generationId' => $generation->id->value,
                'modelId' => $generation->modelId->value,
                'name' => $generation->name,
                'productionStartYear' => $generation->productionStartYear,
                'productionEndYear' => $generation->productionEndYear,
            ],
        );
    }

    public function createVariant(VehicleVariant $variant): void
    {
        $generation = $this->entityManager->find(
            VehicleGenerationRecord::class,
            $variant->generationId->value,
        );

        if (!$generation instanceof VehicleGenerationRecord) {
            throw new InvalidArgumentException('The referenced generation does not exist.');
        }

        if (!$generation->getStatus()->canAcceptChildren()) {
            throw new InvalidArgumentException('A discontinued or archived generation cannot receive new variants.');
        }

        $record = new VehicleVariantRecord(
            $variant->id->value,
            $generation,
            $variant->manufacturerCode,
            $variant->name,
            $variant->fuelType,
            $variant->powertrain,
            $variant->bodyStyle,
            $variant->transmission,
            $variant->technicalSpecifications->powerKw,
            $variant->technicalSpecifications->displacementCc,
            $variant->technicalSpecifications->seats,
            $variant->technicalSpecifications->doors,
            $variant->technicalSpecifications->curbWeightKg,
            $variant->technicalSpecifications->lengthMm,
            $variant->technicalSpecifications->widthMm,
            $variant->technicalSpecifications->heightMm,
            $variant->technicalSpecifications->co2EmissionGPerKm,
            $variant->mediaReferences,
            $variant->status,
        );

        $this->persistWithEvent(
            $record,
            'catalog.vehicle_variant.created',
            $variant->id->value,
            [
                'variantId' => $variant->id->value,
                'generationId' => $variant->generationId->value,
                'name' => $variant->name,
                'manufacturerCode' => $variant->manufacturerCode,
                'fuelType' => $variant->fuelType,
                'powertrain' => $variant->powertrain,
                'bodyStyle' => $variant->bodyStyle,
                'transmission' => $variant->transmission,
                'powerKw' => $variant->technicalSpecifications->powerKw,
                'displacementCc' => $variant->technicalSpecifications->displacementCc,
                'seats' => $variant->technicalSpecifications->seats,
                'doors' => $variant->technicalSpecifications->doors,
                'curbWeightKg' => $variant->technicalSpecifications->curbWeightKg,
                'lengthMm' => $variant->technicalSpecifications->lengthMm,
                'widthMm' => $variant->technicalSpecifications->widthMm,
                'heightMm' => $variant->technicalSpecifications->heightMm,
                'co2EmissionGPerKm' => $variant->technicalSpecifications->co2EmissionGPerKm,
                'mediaReferences' => $variant->mediaReferences,
            ],
        );
    }

    public function publishVariant(string $variantId): bool
    {
        /** @var VehicleVariantRecord|null $variant */
        $variant = $this->entityManager->createQueryBuilder()
            ->select('v', 'g', 'm', 'mf')
            ->from(VehicleVariantRecord::class, 'v')
            ->join('v.generation', 'g')
            ->join('g.model', 'm')
            ->join('m.manufacturer', 'mf')
            ->where('v.id = :id')
            ->setParameter('id', $variantId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($variant === null) {
            return false;
        }

        $generation = $variant->getGeneration();
        $model = $generation->getModel();
        $manufacturer = $model->getManufacturer();

        if (
            $variant->getStatus() === CatalogStatus::PUBLISHED
            && $generation->getStatus() === CatalogStatus::PUBLISHED
            && $model->getStatus() === CatalogStatus::PUBLISHED
            && $manufacturer->getStatus() === CatalogStatus::PUBLISHED
        ) {
            return true;
        }

        if (
            !$generation->getStatus()->canAcceptChildren()
            || !$model->getStatus()->canAcceptChildren()
            || !$manufacturer->getStatus()->canAcceptChildren()
        ) {
            return false;
        }

        try {
            $variant->getStatus()->transitionTo(CatalogStatus::PUBLISHED);
            $generation->getStatus()->transitionTo(CatalogStatus::PUBLISHED);
            $model->getStatus()->transitionTo(CatalogStatus::PUBLISHED);
            $manufacturer->getStatus()->transitionTo(CatalogStatus::PUBLISHED);
        } catch (InvalidArgumentException) {
            return false;
        }

        $eventId = bin2hex(random_bytes(16));
        $event = $this->eventEnvelope(
            $eventId,
            $variantId,
            'catalog.vehicle_variant.published',
            ['variantId' => $variantId],
        );

        $this->entityManager->getConnection()->transactional(function () use (
            $variant,
            $generation,
            $model,
            $manufacturer,
            $eventId,
            $event,
            $variantId,
        ): void {
            $variant->setStatus(CatalogStatus::PUBLISHED);
            $generation->setStatus(CatalogStatus::PUBLISHED);
            $model->setStatus(CatalogStatus::PUBLISHED);
            $manufacturer->setStatus(CatalogStatus::PUBLISHED);
            $this->entityManager->persist(
                new CatalogOutboxRecord(
                    $eventId,
                    $variantId,
                    'catalog.vehicle_variant.published',
                    1,
                    $event,
                ),
            );
            $this->entityManager->flush();
        });

        return true;
    }

    private function createPublishedVehicleQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('v', 'g', 'm', 'mf')
            ->from(VehicleVariantRecord::class, 'v')
            ->join('v.generation', 'g')
            ->join('g.model', 'm')
            ->join('m.manufacturer', 'mf')
            ->where('v.status = :published')
            ->andWhere('g.status = :published')
            ->andWhere('m.status = :published')
            ->andWhere('mf.status = :published')
            ->setParameter('published', CatalogStatus::PUBLISHED);
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param array<string, scalar|null> $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $queryBuilder, array $filters): void
    {
        $columns = [
            'manufacturerId' => 'mf.id',
            'modelId' => 'm.id',
            'generationId' => 'g.id',
            'fuelType' => 'v.fuelType',
            'powertrain' => 'v.powertrain',
            'bodyStyle' => 'v.bodyStyle',
            'transmission' => 'v.transmission',
        ];

        foreach ($columns as $key => $field) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $queryBuilder
                    ->andWhere($field . ' = :' . $key)
                    ->setParameter($key, $filters[$key]);
            }
        }

        foreach ([
            'minPowerKw' => ['v.powerKw', '>='],
            'maxPowerKw' => ['v.powerKw', '<='],
            'minSeats' => ['v.seats', '>='],
            'maxSeats' => ['v.seats', '<='],
        ] as $key => [$field, $operator]) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $queryBuilder
                    ->andWhere($field . ' ' . $operator . ' :' . $key)
                    ->setParameter($key, (int) $filters[$key]);
            }
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $queryBuilder
                ->andWhere(
                    'LOWER(v.name) LIKE :q OR LOWER(m.name) LIKE :q OR LOWER(mf.name) LIKE :q',
                )
                ->setParameter('q', '%' . mb_strtolower(trim((string) $filters['q'])) . '%');
        }
    }

    /** @return array<string, mixed> */
    private function mapVehicle(VehicleVariantRecord $variant): array
    {
        $generation = $variant->getGeneration();
        $model = $generation->getModel();
        $manufacturer = $model->getManufacturer();

        return [
            'variantId' => $variant->getId(),
            'manufacturer' => [
                'manufacturerId' => $manufacturer->getId(),
                'code' => $manufacturer->getCode(),
                'name' => $manufacturer->getName(),
            ],
            'model' => [
                'modelId' => $model->getId(),
                'name' => $model->getName(),
            ],
            'generation' => [
                'generationId' => $generation->getId(),
                'name' => $generation->getName(),
                'productionStartYear' => $generation->getProductionStartYear(),
                'productionEndYear' => $generation->getProductionEndYear(),
            ],
            'manufacturerCode' => $variant->getManufacturerCode(),
            'name' => $variant->getName(),
            'fuelType' => $variant->getFuelType(),
            'powertrain' => $variant->getPowertrain(),
            'bodyStyle' => $variant->getBodyStyle(),
            'transmission' => $variant->getTransmission(),
            'powerKw' => $variant->getPowerKw(),
            'displacementCc' => $variant->getDisplacementCc(),
            'seats' => $variant->getSeats(),
            'doors' => $variant->getDoors(),
            'curbWeightKg' => $variant->getCurbWeightKg(),
            'lengthMm' => $variant->getLengthMm(),
            'widthMm' => $variant->getWidthMm(),
            'heightMm' => $variant->getHeightMm(),
            'co2EmissionGPerKm' => $variant->getCo2EmissionGPerKm(),
            'mediaReferences' => $variant->getMediaReferences(),
            'status' => $variant->getStatus()->value,
            'createdAt' => $variant->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $variant->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistWithEvent(
        object $record,
        string $eventType,
        string $aggregateId,
        array $payload,
    ): void {
        $eventId = bin2hex(random_bytes(16));
        $event = $this->eventEnvelope($eventId, $aggregateId, $eventType, $payload);

        $this->entityManager->getConnection()->transactional(function () use (
            $record,
            $eventId,
            $aggregateId,
            $eventType,
            $event,
        ): void {
            $this->entityManager->persist($record);
            $this->entityManager->persist(
                new CatalogOutboxRecord($eventId, $aggregateId, $eventType, 1, $event),
            );
            $this->entityManager->flush();
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function eventEnvelope(
        string $eventId,
        string $aggregateId,
        string $eventType,
        array $payload,
    ): array {
        return [
            'eventId' => $eventId,
            'eventType' => $eventType,
            'schemaVersion' => 1,
            'aggregateId' => $aggregateId,
            'occurredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'payload' => $payload,
        ];
    }
}
