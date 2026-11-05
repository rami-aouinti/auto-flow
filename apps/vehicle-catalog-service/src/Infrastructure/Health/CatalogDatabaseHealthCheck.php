<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class CatalogDatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function name(): string
    {
        return 'vehicle-catalog-postgres';
    }

    public function check(): HealthCheckResult
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return HealthCheckResult::healthy();
        } catch (Throwable) {
            return HealthCheckResult::unhealthy();
        }
    }
}
