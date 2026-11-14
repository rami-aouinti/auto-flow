<?php
declare(strict_types=1);
namespace App\Controller;
use App\Application\Port\Out\ChatProviderInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
final readonly class ChatController
{
    public function __construct(private ChatProviderInterface $chatProvider) {}
    #[Route('/api/v1/ai/chat', name: 'ai_chat', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try { $payload = $request->toArray(); }
        catch (Throwable) {
            return new JsonResponse(['error' => ['code' => 'invalid_json', 'message' => 'Request body must be valid JSON.']], 400);
        }
        $messages = $payload['messages'] ?? null;
        if (!is_array($messages) || $messages === [] || count($messages) > 30) {
            return new JsonResponse(['error' => ['code' => 'invalid_messages', 'message' => 'Provide between 1 and 30 messages.']], 422);
        }
        $normalizedMessages = [];
        foreach ($messages as $message) {
            if (!is_array($message)
                || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null)
                || trim($message['content']) === ''
                || mb_strlen($message['content']) > 12000
            ) {
                return new JsonResponse(['error' => ['code' => 'invalid_message', 'message' => 'Each message requires a supported role and non-empty content of at most 12000 characters.']], 422);
            }
            $normalizedMessages[] = ['role' => $message['role'], 'content' => $message['content']];
        }
        try { $result = $this->chatProvider->chat($normalizedMessages); }
        catch (RuntimeException) {
            return new JsonResponse(['error' => ['code' => 'ai_provider_unavailable', 'message' => 'The AI provider could not process the request.']], 502);
        }
        return new JsonResponse([
            'data' => ['content' => $result['content'], 'provider' => 'openai', 'model' => $result['model']],
            'usage' => ['input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens']],
        ]);
    }
}
