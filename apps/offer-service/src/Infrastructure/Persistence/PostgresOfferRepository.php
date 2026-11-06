<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Offer;
use App\Domain\Event\DomainEvent;
use App\Domain\Exception\ConcurrentOfferModification;
use App\Domain\Repository\OfferRepositoryInterface;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\OfferStatus;
use App\Domain\ValueObject\PricingSnapshot;
use DateTimeImmutable;
use PDO;
use Throwable;

final readonly class PostgresOfferRepository implements OfferRepositoryInterface
{
    public function __construct(private PostgresConnection $connection)
    {
    }

    public function save(Offer $offer): void
    {
        $events = $offer->domainEvents();
        $expectedStatus = $offer->persistedStatus();

        $this->withinTenantTransaction(
            $offer->tenantId(),
            function (PDO $connection) use ($offer, $events, $expectedStatus): void {
                if ($expectedStatus === null) {
                    $snapshot = $offer->pricingSnapshot();
                    $statement = $connection->prepare(<<<'SQL'
                        INSERT INTO offers (
                            tenant_id, offer_id, created_by, status, quote_id, pricing_snapshot,
                            created_at, expires_at, published_at, accepted_at, rejected_at, expired_at
                        ) VALUES (
                            :tenant_id, :offer_id, :created_by, :status, :quote_id,
                            CAST(:pricing_snapshot AS JSONB), :created_at, :expires_at,
                            :published_at, :accepted_at, :rejected_at, :expired_at
                        )
                        SQL);
                    $statement->execute([
                        'tenant_id' => $offer->tenantId(),
                        'offer_id' => (string) $offer->id(),
                        'created_by' => $offer->createdBy(),
                        'status' => $offer->status()->value,
                        'quote_id' => $snapshot->quoteId,
                        'pricing_snapshot' => json_encode([
                            'quoteId' => $snapshot->quoteId,
                            'vehicleId' => $snapshot->vehicleId,
                            'monthlyAmount' => $snapshot->monthlyAmount,
                            'totalInstallmentsAmount' => $snapshot->totalInstallmentsAmount,
                            'totalCostIncludingDownPayment' => $snapshot->totalCostIncludingDownPayment,
                            'residualValue' => $snapshot->residualValue,
                            'currency' => $snapshot->currency,
                            'durationMonths' => $snapshot->durationMonths,
                            'annualInterestRateBasisPoints' => $snapshot->annualInterestRateBasisPoints,
                            'calculationVersion' => $snapshot->calculationVersion,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $offer->createdAt()->format('Y-m-d\TH:i:s.uP'),
                        'expires_at' => $offer->expiresAt()->format('Y-m-d\TH:i:s.uP'),
                        'published_at' => $offer->publishedAt()?->format('Y-m-d\TH:i:s.uP'),
                        'accepted_at' => $offer->acceptedAt()?->format('Y-m-d\TH:i:s.uP'),
                        'rejected_at' => $offer->rejectedAt()?->format('Y-m-d\TH:i:s.uP'),
                        'expired_at' => $offer->expiredAt()?->format('Y-m-d\TH:i:s.uP'),
                    ]);
                } else {
                    $update = $connection->prepare(<<<'SQL'
                        UPDATE offers
                        SET status = :status,
                            published_at = :published_at,
                            accepted_at = :accepted_at,
                            rejected_at = :rejected_at,
                            expired_at = :expired_at
                        WHERE tenant_id = :tenant_id
                          AND offer_id = :offer_id
                          AND created_by = :created_by
                          AND status = :expected_status
                        SQL);
                    $update->execute([
                        'status' => $offer->status()->value,
                        'tenant_id' => $offer->tenantId(),
                        'offer_id' => (string) $offer->id(),
                        'created_by' => $offer->createdBy(),
                        'expected_status' => $expectedStatus->value,
                        'published_at' => $offer->publishedAt()?->format('Y-m-d\TH:i:s.uP'),
                        'accepted_at' => $offer->acceptedAt()?->format('Y-m-d\TH:i:s.uP'),
                        'rejected_at' => $offer->rejectedAt()?->format('Y-m-d\TH:i:s.uP'),
                        'expired_at' => $offer->expiredAt()?->format('Y-m-d\TH:i:s.uP'),
                    ]);

                    if ($update->rowCount() !== 1) {
                        throw new ConcurrentOfferModification(
                            'The offer was modified by another operation. Reload it and retry.',
                        );
                    }
                }

                self::appendOutboxEvents($connection, $events);
            },
        );

        $offer->markPersisted();
    }

    public function findById(OfferId $id, string $tenantId, string $ownerId): ?Offer
    {
        return $this->withinTenantTransaction(
            $tenantId,
            static function (PDO $connection) use ($id, $ownerId): ?Offer {
                $statement = $connection->prepare(
                    'SELECT * FROM offers WHERE offer_id = :offer_id AND created_by = :created_by LIMIT 1',
                );
                $statement->execute(['offer_id' => (string) $id, 'created_by' => $ownerId]);
                $row = $statement->fetch();

                return is_array($row) ? self::hydrate($row) : null;
            },
        );
    }

    /**
     * @return array{items: list<Offer>, total: int}
     */
    public function findByOwner(
        string $tenantId,
        string $ownerId,
        ?OfferStatus $status,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw new \InvalidArgumentException('Offer list pagination is outside the allowed range.');
        }

        return $this->withinTenantTransaction(
            $tenantId,
            static function (PDO $connection) use ($ownerId, $status, $search, $limit, $offset): array {
                $conditions = ['created_by = :created_by'];
                $parameters = ['created_by' => $ownerId];

                if ($status !== null) {
                    $conditions[] = 'status = :status';
                    $parameters['status'] = $status->value;
                }

                if ($search !== null && trim($search) !== '') {
                    $conditions[] = '(offer_id ILIKE :search_offer OR quote_id ILIKE :search_quote OR pricing_snapshot->>\'vehicleId\' ILIKE :search_vehicle)';
                    $term = '%' . trim($search) . '%';
                    $parameters['search_offer'] = $term;
                    $parameters['search_quote'] = $term;
                    $parameters['search_vehicle'] = $term;
                }

                $where = implode(' AND ', $conditions);
                $count = $connection->prepare('SELECT COUNT(*) FROM offers WHERE ' . $where);
                $count->execute($parameters);
                $total = (int) $count->fetchColumn();

                $statement = $connection->prepare(
                    'SELECT * FROM offers WHERE ' . $where . ' ORDER BY created_at DESC, offer_id DESC LIMIT :limit OFFSET :offset',
                );
                foreach ($parameters as $name => $value) {
                    $statement->bindValue(':' . $name, $value);
                }
                $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
                $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
                $statement->execute();

                $items = array_map(
                    static fn (array $row): Offer => self::hydrate($row),
                    $statement->fetchAll(),
                );

                return ['items' => $items, 'total' => $total];
            },
        );
    }

    public function findExpiredTenantIds(DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Expired tenant query limit must be between 1 and 1000.');
        }

        $statement = $this->connection->get()->prepare(
            'SELECT tenant_id FROM offer_expired_tenants(:batch_size, :now)',
        );
        $statement->execute(['batch_size' => $limit, 'now' => $now->format('Y-m-d\TH:i:s.uP')]);

        return array_values(array_map(
            static fn (array $row): string => (string) $row['tenant_id'],
            $statement->fetchAll(),
        ));
    }

    public function expireDueForTenant(string $tenantId, DateTimeImmutable $now, int $limit): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Expired offer query limit must be between 1 and 1000.');
        }

        return $this->withinTenantTransaction(
            $tenantId,
            static function (PDO $connection) use ($tenantId, $now, $limit): int {
                $statement = $connection->prepare(<<<'SQL'
                    SELECT *
                    FROM offers
                    WHERE status IN ('draft', 'published')
                      AND expires_at <= :now
                    ORDER BY expires_at ASC
                    LIMIT :batch_size
                    FOR UPDATE SKIP LOCKED
                    SQL);
                $statement->bindValue('now', $now->format('Y-m-d\TH:i:s.uP'));
                $statement->bindValue('batch_size', $limit, PDO::PARAM_INT);
                $statement->execute();
                $offers = array_map(
                    static fn (array $row): Offer => self::hydrate($row),
                    $statement->fetchAll(),
                );

                $update = $connection->prepare(<<<'SQL'
                    UPDATE offers
                    SET status = :status,
                        expired_at = :expired_at
                    WHERE tenant_id = :tenant_id
                      AND offer_id = :offer_id
                      AND status = :expected_status
                    SQL);

                foreach ($offers as $offer) {
                    $offer->expire($now);
                    $update->execute([
                        'status' => $offer->status()->value,
                        'tenant_id' => $tenantId,
                        'offer_id' => (string) $offer->id(),
                        'expected_status' => $offer->persistedStatus()?->value,
                        'expired_at' => $offer->expiredAt()?->format('Y-m-d\TH:i:s.uP'),
                    ]);

                    if ($update->rowCount() !== 1) {
                        throw new ConcurrentOfferModification(
                            'The offer changed while the expiration batch was being processed.',
                        );
                    }

                    self::appendOutboxEvents($connection, $offer->domainEvents());
                    $offer->markPersisted();
                }

                return count($offers);
            },
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Offer
    {
        $snapshot = json_decode((string) $row['pricing_snapshot'], true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($snapshot)) {
            throw new \UnexpectedValueException('Stored offer pricing snapshot is invalid.');
        }

        return Offer::reconstitute(
            id: OfferId::fromString((string) $row['offer_id']),
            tenantId: (string) $row['tenant_id'],
            createdBy: (string) $row['created_by'],
            pricingSnapshot: new PricingSnapshot(
                quoteId: (string) $snapshot['quoteId'],
                vehicleId: (string) $snapshot['vehicleId'],
                monthlyAmount: (string) $snapshot['monthlyAmount'],
                totalInstallmentsAmount: (string) $snapshot['totalInstallmentsAmount'],
                totalCostIncludingDownPayment: (string) $snapshot['totalCostIncludingDownPayment'],
                residualValue: (string) $snapshot['residualValue'],
                currency: (string) $snapshot['currency'],
                durationMonths: (int) $snapshot['durationMonths'],
                annualInterestRateBasisPoints: (int) $snapshot['annualInterestRateBasisPoints'],
                calculationVersion: (string) $snapshot['calculationVersion'],
            ),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
            status: OfferStatus::from((string) $row['status']),
            publishedAt: self::nullableDateTime($row['published_at'] ?? null),
            acceptedAt: self::nullableDateTime($row['accepted_at'] ?? null),
            rejectedAt: self::nullableDateTime($row['rejected_at'] ?? null),
            expiredAt: self::nullableDateTime($row['expired_at'] ?? null),
        );
    }

    private static function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    /** @param list<DomainEvent> $events */
    private static function appendOutboxEvents(PDO $connection, array $events): void
    {
        if ($events === []) {
            return;
        }

        $statement = $connection->prepare(<<<'SQL'
            INSERT INTO offer_outbox_events (
                event_id, tenant_id, event_type, aggregate_type, aggregate_id, payload, occurred_at
            ) VALUES (
                :event_id, :tenant_id, :event_type, :aggregate_type, :aggregate_id,
                CAST(:payload AS JSONB), :occurred_at
            )
            ON CONFLICT (event_id) DO NOTHING
            SQL);

        foreach ($events as $event) {
            $envelope = $event->toEnvelope();
            $statement->execute([
                'event_id' => $envelope['eventId'],
                'tenant_id' => $envelope['tenantId'],
                'event_type' => $envelope['eventType'],
                'aggregate_type' => $envelope['aggregate']['type'],
                'aggregate_id' => $envelope['aggregate']['id'],
                'payload' => json_encode($envelope, JSON_THROW_ON_ERROR),
                'occurred_at' => $envelope['occurredAt'],
            ]);
        }
    }

    /**
     * @template TResult
     * @param callable(PDO): TResult $operation
     * @return TResult
     */
    private function withinTenantTransaction(string $tenantId, callable $operation): mixed
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1) {
            throw new \InvalidArgumentException('Tenant identifier has an invalid format.');
        }

        $connection = $this->connection->get();

        if ($connection->inTransaction()) {
            throw new \LogicException('Offer repository operations must own their database transaction.');
        }

        $connection->beginTransaction();

        try {
            $tenantStatement = $connection->prepare("SELECT set_config('app.tenant_id', :tenant_id, true)");
            $tenantStatement->execute(['tenant_id' => $tenantId]);
            $result = $operation($connection);
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }
}
