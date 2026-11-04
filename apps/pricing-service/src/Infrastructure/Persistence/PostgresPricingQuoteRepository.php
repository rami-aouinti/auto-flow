<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\Out\PricingQuoteRepositoryInterface;
use App\Domain\Model\PricingQuote;
use LogicException;
use PDO;
use Throwable;

final readonly class PostgresPricingQuoteRepository implements PricingQuoteRepositoryInterface
{
    public function __construct(
        private PDO $connection,
    ) {
    }

    public function save(PricingQuote $quote): void
    {
        if ($this->connection->inTransaction()) {
            throw new LogicException('Pricing quote persistence must own its database transaction.');
        }

        $this->connection->beginTransaction();

        try {
            $tenantStatement = $this->connection->prepare(
                "SELECT set_config('app.tenant_id', :tenant_id, true)",
            );
            $tenantStatement->execute(['tenant_id' => $quote->tenantId]);

            $statement = $this->connection->prepare(
                <<<'SQL'
                INSERT INTO pricing_quotes (
                    quote_id,
                    tenant_id,
                    vehicle_id,
                    monthly_amount_minor,
                    total_installments_minor,
                    total_cost_minor,
                    residual_value_minor,
                    currency,
                    duration_months,
                    annual_interest_rate_basis_points,
                    calculation_version
                ) VALUES (
                    :quote_id,
                    :tenant_id,
                    :vehicle_id,
                    :monthly_amount_minor,
                    :total_installments_minor,
                    :total_cost_minor,
                    :residual_value_minor,
                    :currency,
                    :duration_months,
                    :annual_interest_rate_basis_points,
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

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
