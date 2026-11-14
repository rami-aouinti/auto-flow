<?php
declare(strict_types=1);
namespace App\Infrastructure\Health;
use App\Infrastructure\Elasticsearch\ElasticsearchClient;
use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;
final readonly class SearchReadinessCheck implements HealthCheckInterface
{
    public function __construct(private ElasticsearchClient $elasticsearch) {}
    public function name(): string { return 'elasticsearch'; }
    public function check(): HealthCheckResult
    {
        return $this->elasticsearch->ping()
            ? HealthCheckResult::healthy()
            : HealthCheckResult::unhealthy(['error' => 'Elasticsearch is unavailable.']);
    }
}
