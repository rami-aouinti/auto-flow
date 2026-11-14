<?php
declare(strict_types=1);
namespace App\Infrastructure\Health;
use App\Infrastructure\Audit\AuditRepository;
use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;
final readonly class AuditReadinessCheck implements HealthCheckInterface
{
    public function __construct(private AuditRepository $repository) {}
    public function name(): string { return 'postgres'; }
    public function check(): HealthCheckResult
    {
        return $this->repository->ping()
            ? HealthCheckResult::healthy()
            : HealthCheckResult::unhealthy(['error'=>'PostgreSQL is unavailable.']);
    }
}
