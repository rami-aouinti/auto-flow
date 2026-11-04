<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Infrastructure\Persistence\PostgresConnection;
use App\Infrastructure\Persistence\PostgresPricingPolicyRepository;
use App\Tests\Fixtures\PricingPolicyFixture;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PricingPolicyRepositoryTest extends TestCase
{
    private string $databaseUrl;

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
    }

    public function testPolicyRepositoryEnforcesTenantRlsAndLoadsActiveVersion(): void
    {
        $repository = new PostgresPricingPolicyRepository(new PostgresConnection($this->databaseUrl));
        $tenantId = 'tenant-policy-' . bin2hex(random_bytes(4));
        $policyId = '123e4567-e89b-42d3-a456-' . bin2hex(random_bytes(6));
        $draft = PricingPolicyFixture::draft($tenantId, random_int(100, 100000), $policyId);
        $repository->save($draft);

        self::assertNull($repository->findByVersion('tenant-other', $draft->version));
        self::assertNull($repository->findActive($tenantId));

        $active = $draft->activate(new \DateTimeImmutable('now'));
        $repository->save($active);

        $loaded = $repository->findActive('tenant-policy-test');
        self::assertNotNull($loaded);
        self::assertSame($draft->version, $loaded->version);
        self::assertSame('active', $loaded->status);
        self::assertNull($repository->findByVersion('tenant-other', $draft->version));

        $pdo = (new PostgresConnection($this->databaseUrl))->get();
        $pdo->beginTransaction();
        try {
            $tenant = $pdo->prepare("SELECT set_config('app.tenant_id', :tenant_id, true)");
            $tenant->execute(['tenant_id' => $tenantId]);
            $update = $pdo->prepare(
                'UPDATE pricing_policies SET maximum_annual_rate_bps = 1900 WHERE policy_id = :policy_id',
            );
            $this->expectException(PDOException::class);
            $update->execute(['policy_id' => $policyId]);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
