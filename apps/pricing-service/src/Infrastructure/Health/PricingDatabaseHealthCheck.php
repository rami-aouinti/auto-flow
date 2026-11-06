<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use App\Infrastructure\Persistence\PostgresConnection;
use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;
use Throwable;

final readonly class PricingDatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private PostgresConnection $connection)
    {
    }

    public function name(): string
    {
        return 'pricing_database';
    }

    public function check(): HealthCheckResult
    {
        try {
            $this->connection->get()->query('SELECT 1')->fetchColumn();

            return HealthCheckResult::healthy();
        } catch (Throwable) {
            return HealthCheckResult::unhealthy();
        }
    }
}
