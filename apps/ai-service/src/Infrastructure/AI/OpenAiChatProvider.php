<?php
declare(strict_types=1);
namespace App\Infrastructure\AI;
use App\Application\Port\Out\ChatProviderInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
final readonly class OpenAiChatProvider implements ChatProviderInterface
{
    public function __construct(private HttpClientInterface $httpClient, private string $apiKey, private string $baseUri, private string $model) {}
    public function chat(array $messages): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('The OpenAI provider is not configured.');
        }
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUri, '/') . '/chat/completions', [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'],
                'json' => ['model' => $this->model, 'messages' => $messages],
                'timeout' => 30,
            ]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
            if ($statusCode >= 400) {
                throw new RuntimeException(sprintf('The AI provider returned HTTP %d.', $statusCode));
            }
            $content = $payload['choices'][0]['message']['content'] ?? null;
            if (!is_string($content)) {
                throw new RuntimeException('The AI provider returned an invalid response.');
            }
            return [
                'content' => $content,
                'model' => (string) ($payload['model'] ?? $this->model),
                'input_tokens' => isset($payload['usage']['prompt_tokens']) ? (int) $payload['usage']['prompt_tokens'] : null,
                'output_tokens' => isset($payload['usage']['completion_tokens']) ? (int) $payload['usage']['completion_tokens'] : null,
            ];
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException('The AI provider is temporarily unreachable.', previous: $exception);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) { throw $exception; }
            throw new RuntimeException('The AI provider request failed.', previous: $exception);
        }
    }
}
