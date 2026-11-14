<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;

final readonly class OpenAiConfigurationHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private string $apiKey,
        private string $baseUri,
        private string $model,
    ) {
    }

    public function name(): string
    {
        return 'openai_provider_configuration';
    }

    public function check(): HealthCheckResult
    {
        $configured = trim($this->apiKey) !== ''
            && filter_var($this->baseUri, FILTER_VALIDATE_URL) !== false
            && trim($this->model) !== '';

        return $configured
            ? HealthCheckResult::healthy()
            : HealthCheckResult::unhealthy();
    }
}
