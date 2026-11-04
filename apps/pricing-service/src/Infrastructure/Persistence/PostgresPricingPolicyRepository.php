<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\Out\PricingPolicyRepositoryInterface;
use App\Domain\Model\PricingPolicy;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

final readonly class PostgresPricingPolicyRepository implements PricingPolicyRepositoryInterface
{
    public function __construct(private PostgresConnection $connection)
    {
    }

    public function save(PricingPolicy $policy): void
    {
        $this->withinTenant($policy->tenantId, function (PDO $pdo) use ($policy): void {
            if ($policy->status === 'active') {
                $retire = $pdo->prepare("UPDATE pricing_policies SET status = 'retired' WHERE tenant_id = :tenant AND status = 'active' AND policy_id <> :policy_id");
                $retire->execute(['tenant' => $policy->tenantId, 'policy_id' => $policy->policyId]);
            }
            $statement = $pdo->prepare(
                'INSERT INTO pricing_policies (policy_id, tenant_id, version, status, minimum_duration_months, maximum_duration_months, minimum_annual_rate_bps, maximum_annual_rate_bps, quote_validity_days, calculation_version, created_by, created_at, published_at) VALUES (:id, :tenant, :version, :status, :min_duration, :max_duration, :min_rate, :max_rate, :validity, :calculation, :creator, :created_at, :published_at) ON CONFLICT (policy_id) DO UPDATE SET status = EXCLUDED.status, published_at = EXCLUDED.published_at',
            );
            $statement->execute([
                'id' => $policy->policyId,
                'tenant' => $policy->tenantId,
                'version' => $policy->version,
                'status' => $policy->status,
                'min_duration' => $policy->minimumDurationMonths,
                'max_duration' => $policy->maximumDurationMonths,
                'min_rate' => $policy->minimumAnnualRateBasisPoints,
                'max_rate' => $policy->maximumAnnualRateBasisPoints,
                'validity' => $policy->quoteValidityDays,
                'calculation' => $policy->calculationVersion,
                'creator' => $policy->createdBy,
                'created_at' => $policy->createdAt->format('Y-m-d H:i:sP'),
                'published_at' => $policy->publishedAt?->format('Y-m-d H:i:sP'),
            ]);
        });
    }

    public function findActive(string $tenantId): ?PricingPolicy
    {
        return $this->findOne($tenantId, 'status = :status', ['status' => 'active']);
    }

    public function findLatest(string $tenantId): ?PricingPolicy
    {
        return $this->findOne($tenantId, 'TRUE ORDER BY version DESC', []);
    }

    public function findByVersion(string $tenantId, int $version): ?PricingPolicy
    {
        if ($version < 1) {
            throw new InvalidArgumentException('Pricing policy version must be positive.');
        }
        return $this->findOne($tenantId, 'version = :version', ['version' => $version]);
    }

    /** @param array<string, int|string> $criteria */
    private function findOne(string $tenantId, string $where, array $criteria): ?PricingPolicy
    {
        return $this->withinTenant($tenantId, static function (PDO $pdo) use ($where, $criteria): ?PricingPolicy {
            $statement = $pdo->prepare('SELECT * FROM pricing_policies WHERE ' . $where . ' LIMIT 1');
            $statement->execute($criteria);
            $row = $statement->fetch();
            if (!is_array($row)) {
                return null;
            }
            return new PricingPolicy(
                policyId: (string) $row['policy_id'],
                tenantId: (string) $row['tenant_id'],
                version: (int) $row['version'],
                status: (string) $row['status'],
                minimumDurationMonths: (int) $row['minimum_duration_months'],
                maximumDurationMonths: (int) $row['maximum_duration_months'],
                minimumAnnualRateBasisPoints: (int) $row['minimum_annual_rate_bps'],
                maximumAnnualRateBasisPoints: (int) $row['maximum_annual_rate_bps'],
                quoteValidityDays: (int) $row['quote_validity_days'],
                calculationVersion: (string) $row['calculation_version'],
                createdBy: (string) $row['created_by'],
                createdAt: new DateTimeImmutable((string) $row['created_at']),
                publishedAt: $row['published_at'] === null ? null : new DateTimeImmutable((string) $row['published_at']),
            );
        });
    }

    /** @template T @param callable(PDO): T $callback @return T */
    private function withinTenant(string $tenantId, callable $callback): mixed
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)) {
            throw new InvalidArgumentException('Tenant identifier has an invalid format.');
        }
        $pdo = $this->connection->get();
        if ($pdo->inTransaction()) {
            throw new LogicException('Pricing policy repository operations must own their database transaction.');
        }
        $pdo->beginTransaction();
        try {
            $tenant = $pdo->prepare("SELECT set_config('app.tenant_id', :tenant_id, true)");
            $tenant->execute(['tenant_id' => $tenantId]);
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
