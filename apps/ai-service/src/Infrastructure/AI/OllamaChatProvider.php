<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\Port\Out\ChatProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class OllamaChatProvider implements ChatProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUri,
        private string $model,
    ) {
    }

    public function chat(array $messages): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUri, '/') . '/api/chat', [
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'stream' => false,
                ],
                'timeout' => 180,
            ]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);

            if ($statusCode >= 400) {
                throw new RuntimeException(sprintf('Ollama returned HTTP %d.', $statusCode));
            }

            $content = $payload['message']['content'] ?? null;
            if (!is_string($content)) {
                throw new RuntimeException('Ollama returned an invalid response.');
            }

            return [
                'content' => $content,
                'model' => (string) ($payload['model'] ?? $this->model),
                'input_tokens' => isset($payload['prompt_eval_count']) ? (int) $payload['prompt_eval_count'] : null,
                'output_tokens' => isset($payload['eval_count']) ? (int) $payload['eval_count'] : null,
            ];
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException('The local Ollama service is temporarily unreachable.', previous: $exception);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('The local Ollama request failed.', previous: $exception);
        }
    }
}
