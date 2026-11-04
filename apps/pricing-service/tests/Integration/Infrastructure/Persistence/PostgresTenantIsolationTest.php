<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Infrastructure\Persistence\PostgresConnectionFactory;
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
                calculation_version
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
                'lease-annuity-v1'
            )
            SQL,
        );
        $statement->execute([
            'quote_id' => $quoteId,
            'tenant_id' => $tenantId,
        ]);
    }
}
