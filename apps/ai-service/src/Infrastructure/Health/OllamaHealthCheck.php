<?php
declare(strict_types=1);

namespace App\Infrastructure\Health;

use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use AutoFlow\CoreBundle\Health\HealthCheckResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class OllamaHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUri,
        private string $model,
    ) {
    }

    public function name(): string
    {
        return 'ollama_model';
    }

    public function check(): HealthCheckResult
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUri, '/') . '/api/tags', [
                'timeout' => 3,
            ]);
            if ($response->getStatusCode() !== 200) {
                return HealthCheckResult::unhealthy();
            }

            $payload = $response->toArray(false);
            foreach (($payload['models'] ?? []) as $model) {
                if (($model['name'] ?? null) === $this->model || ($model['model'] ?? null) === $this->model) {
                    return HealthCheckResult::healthy();
                }
            }
        } catch (Throwable) {
            return HealthCheckResult::unhealthy();
        }

        return HealthCheckResult::unhealthy();
    }
}
