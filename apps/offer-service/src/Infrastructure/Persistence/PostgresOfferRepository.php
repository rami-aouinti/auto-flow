<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Offer;
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
        $this->withinTenantTransaction($offer->tenantId(), function (PDO $connection) use ($offer): void {
            $snapshot = $offer->pricingSnapshot();
            $statement = $connection->prepare(<<<'SQL'
                INSERT INTO offers (
                    tenant_id, offer_id, created_by, status, quote_id, pricing_snapshot,
                    created_at, expires_at
                ) VALUES (
                    :tenant_id, :offer_id, :created_by, :status, :quote_id,
                    CAST(:pricing_snapshot AS JSONB), :created_at, :expires_at
                )
                ON CONFLICT (tenant_id, offer_id) DO UPDATE
                SET status = EXCLUDED.status
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
                'created_at' => $offer->createdAt()->format(DATE_ATOM),
                'expires_at' => $offer->expiresAt()->format(DATE_ATOM),
            ]);
        });
    }

    public function findById(OfferId $id, string $tenantId): ?Offer
    {
        return $this->withinTenantTransaction(
            $tenantId,
            static function (PDO $connection) use ($id): ?Offer {
                $statement = $connection->prepare(
                    'SELECT * FROM offers WHERE offer_id = :offer_id LIMIT 1',
                );
                $statement->execute(['offer_id' => (string) $id]);
                $row = $statement->fetch();

                if (!is_array($row)) {
                    return null;
                }

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
                );
            },
        );
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
