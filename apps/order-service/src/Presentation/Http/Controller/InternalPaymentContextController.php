<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\GetOrderPaymentContext\GetOrderPaymentContextHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/internal/orders')]
final readonly class InternalPaymentContextController
{
    public function __construct(
        private GetOrderPaymentContextHandler $getPaymentContext,
        private string $paymentSharedSecret,
    ) {
    }

    #[Route('/{orderId}/payment-context', methods: ['GET'], requirements: ['orderId' => '[0-9a-fA-F-]{36}'])]
    public function __invoke(Request $request, string $orderId): JsonResponse
    {
        $provided = $request->headers->get('X-Autoflow-Service-Token', '');
        if ($this->paymentSharedSecret === '' || !is_string($provided)
            || !hash_equals($this->paymentSharedSecret, $provided)) {
            return new JsonResponse(['detail' => 'Service authentication failed.'], 403);
        }

        $tenantId = $request->headers->get('X-Tenant-ID', '');
        if (!is_string($tenantId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1) {
            return new JsonResponse(['detail' => 'A valid tenant context is required.'], 400);
        }

        try {
            $result = ($this->getPaymentContext)($tenantId, $orderId);
        } catch (\\Throwable) {
            return new JsonResponse(['detail' => 'The order payment context is unavailable.'], 503);
        }

        if ($result === null) {
            return new JsonResponse(['detail' => 'Order not found.'], 404);
        }
        if (($result['notPayable'] ?? false) === true) {
            return new JsonResponse(['detail' => 'Only accepted orders can be paid.', 'orderStatus' => $result['status']], 409);
        }
        return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
    }

}
