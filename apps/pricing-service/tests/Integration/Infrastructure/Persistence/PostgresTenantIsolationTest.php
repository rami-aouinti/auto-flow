<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Model\PricingQuote;
use App\Domain\ValueObject\Money;
use App\Infrastructure\Persistence\PostgresConnection;
use App\Infrastructure\Persistence\PostgresConnectionFactory;
use App\Infrastructure\Persistence\PostgresPricingQuoteRepository;
use App\Tests\Fixtures\PricingQuoteFixture;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
#[CoversNothing]
final class PostgresTenantIsolationTest extends TestCase
{
    private PDO $connection;

    private string $databaseUrl;

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped('PostgreSQL integration tests are disabled.');
        }

        $databaseUrl = getenv('DATABASE_URL');
        if (!is_string($databaseUrl) || $databaseUrl === '') {
            self::fail('DATABASE_URL must be configured for PostgreSQL integration tests.');
        }

        $this->databaseUrl = $databaseUrl;
        $this->connection = PostgresConnectionFactory::create($databaseUrl);
    }

    public function testRowLevelSecurityHidesAndRejectsRowsFromOtherTenants(): void
    {
        $quoteId = bin2hex(random_bytes(16));
        $this->connection->beginTransaction();

        try {
            $this->setTenant('tenant-a');
            $this->insertQuote($quoteId, 'tenant-a');

            $this->setTenant('tenant-b');
            $select = $this->connection->prepare(
                'SELECT COUNT(*) FROM pricing_quotes WHERE quote_id = :quote_id',
            );
            $select->execute(['quote_id' => $quoteId]);

            self::assertSame(0, (int) $select->fetchColumn());

            $this->expectException(PDOException::class);
            $this->insertQuote(bin2hex(random_bytes(16)), 'tenant-a');
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }
    }

    public function testQueriesWithoutTenantContextCannotReadTenantRows(): void
    {
        $quoteId = bin2hex(random_bytes(16));
        $this->connection->beginTransaction();

        try {
            $this->setTenant('tenant-a');
            $this->insertQuote($quoteId, 'tenant-a');
            $this->connection->commit();

            $this->connection->beginTransaction();
            $this->connection->exec("SELECT set_config('app.tenant_id', '', true)");
            $select = $this->connection->prepare(
                'SELECT COUNT(*) FROM pricing_quotes WHERE quote_id = :quote_id',
            );
            $select->execute(['quote_id' => $quoteId]);

            self::assertSame(0, (int) $select->fetchColumn());
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }
    }


    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function testRepositoryPersistsQuotesWithinTheSuppliedTenantBoundary(): void
    {
        $quoteId = bin2hex(random_bytes(16));
        $quote = new PricingQuote(
            quoteId: $quoteId,
            tenantId: 'tenant-a',
            createdBy: 'user-a',
            vehicleId: 'vehicle-001',
            monthlyAmount: Money::fromMinorUnits(50000, 'EUR'),
            totalInstallmentsAmount: Money::fromMinorUnits(1800000, 'EUR'),
            totalCostIncludingDownPayment: Money::fromMinorUnits(2100000, 'EUR'),
            residualValue: Money::fromMinorUnits(900000, 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 0,
            calculationVersion: 'lease-annuity-v1',
        );

        (new PostgresPricingQuoteRepository(new PostgresConnection($this->databaseUrl)))->save($quote);

        $this->connection->beginTransaction();

        try {
            $this->setTenant('tenant-b');
            $select = $this->connection->prepare(
                'SELECT COUNT(*) FROM pricing_quotes WHERE quote_id = :quote_id',
            );
            $select->execute(['quote_id' => $quoteId]);
            self::assertSame(0, (int) $select->fetchColumn());

            $this->setTenant('tenant-a');
            $select->execute(['quote_id' => $quoteId]);
            self::assertSame(1, (int) $select->fetchColumn());
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }
    }


    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function testRepositoryReadsListsAndDeletesOnlyWithinTheCurrentTenant(): void
    {
        $quoteId = bin2hex(random_bytes(16));
        $quote = new PricingQuote(
            quoteId: $quoteId,
            tenantId: 'tenant-a',
            createdBy: 'user-a',
            vehicleId: 'vehicle-002',
            monthlyAmount: Money::fromMinorUnits(42000, 'EUR'),
            totalInstallmentsAmount: Money::fromMinorUnits(1512000, 'EUR'),
            totalCostIncludingDownPayment: Money::fromMinorUnits(1812000, 'EUR'),
            residualValue: Money::fromMinorUnits(800000, 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 250,
            calculationVersion: 'lease-annuity-v1',
        );
        $repository = new PostgresPricingQuoteRepository(new PostgresConnection($this->databaseUrl));
        $repository->save($quote);

        self::assertNull($repository->findById('tenant-b', $quoteId, null));
        self::assertSame([], $repository->findAll('tenant-b', null));
        self::assertFalse($repository->delete('tenant-b', $quoteId, null));

        $found = $repository->findById('tenant-a', $quoteId, 'user-a');
        self::assertNotNull($found);
        self::assertSame('tenant-a', $found->tenantId);
        self::assertSame($quoteId, $found->quoteId);

        $tenantQuotes = $repository->findAll('tenant-a', 'user-a');
        self::assertContainsOnlyInstancesOf(PricingQuote::class, $tenantQuotes);
        self::assertContainsEquals($found, $tenantQuotes);

        self::assertTrue($repository->delete('tenant-a', $quoteId, 'user-a'));
        self::assertNull($repository->findById('tenant-a', $quoteId, 'user-a'));

        $this->connection->beginTransaction();

        try {
            $this->setTenant('tenant-a');
            $auditStatement = $this->connection->prepare(
                'SELECT deleted_at FROM pricing_quotes WHERE quote_id = :quote_id',
            );
            $auditStatement->execute(['quote_id' => $quoteId]);

            self::assertNotFalse($auditStatement->fetchColumn());
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }
    }


    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function testRepositoryRestrictsQuoteReadsListsAndDeletesToTheCreatorWithinOneTenant(): void
    {
        $ownerQuoteId = bin2hex(random_bytes(16));
        $otherQuoteId = bin2hex(random_bytes(16));
        $repository = new PostgresPricingQuoteRepository(new PostgresConnection($this->databaseUrl));

        $ownerQuote = new PricingQuote(
            quoteId: $ownerQuoteId,
            tenantId: 'tenant-a',
            createdBy: 'user-a',
            vehicleId: 'vehicle-owner',
            monthlyAmount: Money::fromMinorUnits(50000, 'EUR'),
            totalInstallmentsAmount: Money::fromMinorUnits(1800000, 'EUR'),
            totalCostIncludingDownPayment: Money::fromMinorUnits(2100000, 'EUR'),
            residualValue: Money::fromMinorUnits(900000, 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 0,
            calculationVersion: 'lease-annuity-v1',
        );
        $otherQuote = new PricingQuote(
            quoteId: $otherQuoteId,
            tenantId: 'tenant-a',
            createdBy: 'user-b',
            vehicleId: 'vehicle-other',
            monthlyAmount: Money::fromMinorUnits(42000, 'EUR'),
            totalInstallmentsAmount: Money::fromMinorUnits(1512000, 'EUR'),
            totalCostIncludingDownPayment: Money::fromMinorUnits(1812000, 'EUR'),
            residualValue: Money::fromMinorUnits(800000, 'EUR'),
            durationMonths: 36,
            annualInterestRateBasisPoints: 250,
            calculationVersion: 'lease-annuity-v1',
        );

        $repository->save($ownerQuote);
        $repository->save($otherQuote);

        self::assertNotNull($repository->findById('tenant-a', $ownerQuoteId, 'user-a'));
        self::assertNull($repository->findById('tenant-a', $ownerQuoteId, 'user-b'));
        self::assertSame([$otherQuoteId], array_map(
            static fn (PricingQuote $quote): string => $quote->quoteId,
            $repository->findAll('tenant-a', 'user-b'),
        ));
        self::assertFalse($repository->delete('tenant-a', $ownerQuoteId, 'user-b'));
        self::assertTrue($repository->delete('tenant-a', $ownerQuoteId, 'user-a'));
        self::assertNull($repository->findById('tenant-a', $ownerQuoteId, 'user-a'));
    }

    public function testDatabaseRejectsMutationOfPersistedQuoteSnapshot(): void
    {
        $quote = PricingQuoteFixture::demo(
            bin2hex(random_bytes(16)),
            'tenant-a',
            'user-a',
            'vehicle-immutable',
        );
        $repository = new PostgresPricingQuoteRepository(new PostgresConnection($this->databaseUrl));
        $repository->save($quote);

        $this->connection->beginTransaction();
        try {
            $this->setTenant('tenant-a');
            $update = $this->connection->prepare(
                'UPDATE pricing_quotes SET monthly_amount_minor = 1 WHERE quote_id = :quote_id',
            );
            $this->expectException(PDOException::class);
            $update->execute(['quote_id' => $quote->quoteId]);
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }
    }

    private function setTenant(string $tenantId): void
    {
        $statement = $this->connection->prepare(
            "SELECT set_config('app.tenant_id', :tenant_id, true)",
        );
        $statement->execute(['tenant_id' => $tenantId]);
    }

    private function insertQuote(string $quoteId, string $tenantId): void
    {
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
                calculation_version,
                expires_at
            ) VALUES (
                :quote_id,
                :tenant_id,
                'vehicle-001',
                50000,
                1800000,
                2100000,
                900000,
                'EUR',
                36,
                0,
                'lease-annuity-v1',
                CURRENT_TIMESTAMP + INTERVAL '7 days'
            )
            SQL,
        );
        $statement->execute([
            'quote_id' => $quoteId,
            'tenant_id' => $tenantId,
        ]);
    }
}
