<?php

declare(strict_types=1);

namespace App\Controller;

use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[AsController]
/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class PricingController
{
    #[Route('/health', name: 'pricing_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'pricing-service',
            'status' => 'ok',
        ]);
    }

    #[Route('/api/pricing/{vehicleId}', name: 'pricing_quote', methods: ['GET'])]
    public function quote(string $vehicleId): JsonResponse
    {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.pricing-service', '1.0.0')
            ->spanBuilder('pricing.calculate')
            ->startSpan();

        $scope = Context::storage()->attach(
            $span->storeInContext(Context::getCurrent()),
        );

        try {
            $span->setAttribute('vehicle.id', $vehicleId);

            return new JsonResponse([
                'vehicleId' => $vehicleId,
                'monthlyAmount' => 329.00,
                'currency' => 'EUR',
                'durationMonths' => 48,
                'calculation' => 'demo-only',
            ]);
        } catch (Throwable $exception) {
            $span->recordException($exception);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
