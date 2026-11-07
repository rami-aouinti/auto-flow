<?php
declare(strict_types=1);

namespace App\Infrastructure\Health;

use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class ContractDatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function name(): string
    {
        return 'contract_database';
    }

    public function check(): HealthCheckResult
    {
        try {
            $this->db->executeQuery('SELECT 1')->fetchOne();

            return HealthCheckResult::healthy();
        } catch (Throwable) {
            return HealthCheckResult::unhealthy();
        }
    }
}
