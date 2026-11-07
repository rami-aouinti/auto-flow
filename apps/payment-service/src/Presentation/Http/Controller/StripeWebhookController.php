<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\PaymentApplicationService;
use App\Infrastructure\Webhook\StripeWebhookVerifier;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class StripeWebhookController
{
    public function __construct(
        private StripeWebhookVerifier $verifier,
        private PaymentApplicationService $payments,
    ) {
    }

    #[Route('/api/v1/payments/webhooks/stripe', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        try {
            $this->verifier->verify($payload, $request->headers->get('Stripe-Signature') ?? '');
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($event)) {
                return new JsonResponse(['error' => 'invalid_event'], 400);
            }
            $this->payments->applyWebhook($event);
            return new JsonResponse(['received' => true], 200);
        } catch (JsonException) {
            return new JsonResponse(['error' => 'invalid_json'], 400);
        } catch (Throwable) {
            return new JsonResponse(['error' => 'invalid_webhook'], 400);
        }
    }
}
