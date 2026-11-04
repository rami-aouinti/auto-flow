<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\Out\PricingQuoteRepositoryInterface;
use App\Domain\Model\PricingQuote;
use App\Domain\ValueObject\Money;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final readonly class PostgresPricingQuoteRepository implements PricingQuoteRepositoryInterface
{
    public function __construct(
        private PostgresConnection $connection,
    ) {
    }

    public function save(PricingQuote $quote): void
    {
        $this->withinTenantTransaction($quote->tenantId, function (PDO $connection) use ($quote): void {
            $statement = $connection->prepare(
                <<<'SQL'
                INSERT INTO pricing_quotes (
                    quote_id, tenant_id, vehicle_id, monthly_amount_minor,
                    total_installments_minor, total_cost_minor, residual_value_minor,
                    currency, duration_months, annual_interest_rate_basis_points,
                    calculation_version
                ) VALUES (
                    :quote_id, :tenant_id, :vehicle_id, :monthly_amount_minor,
                    :total_installments_minor, :total_cost_minor, :residual_value_minor,
                    :currency, :duration_months, :annual_interest_rate_basis_points,
                    :calculation_version
                )
                SQL,
            );

            $statement->execute([
                'quote_id' => $quote->quoteId,
                'tenant_id' => $quote->tenantId,
                'vehicle_id' => $quote->vehicleId,
                'monthly_amount_minor' => $quote->monthlyAmount->minorUnits,
                'total_installments_minor' => $quote->totalInstallmentsAmount->minorUnits,
                'total_cost_minor' => $quote->totalCostIncludingDownPayment->minorUnits,
                'residual_value_minor' => $quote->residualValue->minorUnits,
                'currency' => $quote->monthlyAmount->currency,
                'duration_months' => $quote->durationMonths,
                'annual_interest_rate_basis_points' => $quote->annualInterestRateBasisPoints,
                'calculation_version' => $quote->calculationVersion,
            ]);
        });
    }

    public function findById(string $tenantId, string $quoteId): ?PricingQuote
    {
        return $this->withinTenantTransaction(
            $tenantId,
            static function (PDO $connection) use ($quoteId): ?PricingQuote {
                $statement = $connection->prepare(
                    'SELECT * FROM pricing_quotes WHERE quote_id = :quote_id AND deleted_at IS NULL',
                );
                $statement->execute(['quote_id' => $quoteId]);
                $row = $statement->fetch();

                return is_array($row) ? self::hydrate($row) : null;
            },
        );
    }

    /**
     * @return list<PricingQuote>
     */
    public function findAll(string $tenantId, int $limit = 50, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new InvalidArgumentException('Quote pagination must use limit 1-100 and a non-negative offset.');
        }

        return $this->withinTenantTransaction(
            $tenantId,
            static function (PDO $connection) use ($limit, $offset): array {
                $statement = $connection->prepare(
                    <<<'SQL'
                    SELECT *
                    FROM pricing_quotes
                    WHERE deleted_at IS NULL
                    ORDER BY created_at DESC, quote_id ASC
                    LIMIT :limit OFFSET :offset
                    SQL,
                );
                $statement->bindValue('limit', $limit, PDO::PARAM_INT);
                $statement->bindValue('offset', $offset, PDO::PARAM_INT);
                $statement->execute();

                $quotes = [];
                while (($row = $statement->fetch()) !== false) {
                    $quotes[] = self::hydrate($row);
                }

                return $quotes;
            },
        );
    }

    public function delete(string $tenantId, string $quoteId): bool
    {
        return $this->withinTenantTransaction(
            $tenantId,
            static function (PDO $connection) use ($quoteId): bool {
                $statement = $connection->prepare(
                    <<<'SQL'
                    UPDATE pricing_quotes
                    SET deleted_at = CURRENT_TIMESTAMP
                    WHERE quote_id = :quote_id AND deleted_at IS NULL
                    SQL,
                );
                $statement->execute(['quote_id' => $quoteId]);

                return $statement->rowCount() === 1;
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
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)) {
            throw new InvalidArgumentException('Tenant identifier has an invalid format.');
        }

        $connection = $this->connection->get();

        if ($connection->inTransaction()) {
            throw new LogicException('Pricing quote repository operations must own their database transaction.');
        }

        $connection->beginTransaction();

        try {
            $tenantStatement = $connection->prepare(
                "SELECT set_config('app.tenant_id', :tenant_id, true)",
            );
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

    /**
     * @param array<string, mixed> $row
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private static function hydrate(array $row): PricingQuote
    {
        return new PricingQuote(
            quoteId: trim((string) $row['quote_id']),
            tenantId: (string) $row['tenant_id'],
            vehicleId: (string) $row['vehicle_id'],
            monthlyAmount: Money::fromMinorUnits((int) $row['monthly_amount_minor'], (string) $row['currency']),
            totalInstallmentsAmount: Money::fromMinorUnits(
                (int) $row['total_installments_minor'],
                (string) $row['currency'],
            ),
            totalCostIncludingDownPayment: Money::fromMinorUnits(
                (int) $row['total_cost_minor'],
                (string) $row['currency'],
            ),
            residualValue: Money::fromMinorUnits((int) $row['residual_value_minor'], (string) $row['currency']),
            durationMonths: (int) $row['duration_months'],
            annualInterestRateBasisPoints: (int) $row['annual_interest_rate_basis_points'],
            calculationVersion: (string) $row['calculation_version'],
        );
    }
}
