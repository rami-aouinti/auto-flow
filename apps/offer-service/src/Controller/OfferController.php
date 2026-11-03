<?php

declare(strict_types=1);

namespace App\Controller;

use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[AsController]
final class OfferController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $pricingServiceUrl,
    ) {
    }

    #[Route('/health', name: 'offer_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'offer-service',
            'status' => 'ok',
        ]);
    }

    #[Route('/api/offers/{vehicleId}', name: 'offer_show', methods: ['GET'])]
    public function show(string $vehicleId): JsonResponse
    {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.offer-service', '1.0.0')
            ->spanBuilder('offer.compose')
            ->startSpan();

        $scope = Context::storage()->attach(
            $span->storeInContext(Context::getCurrent()),
        );

        try {
            $span->setAttribute('vehicle.id', $vehicleId);

            $pricingResponse = $this->httpClient->request(
                'GET',
                sprintf(
                    '%s/api/pricing/%s',
                    rtrim($this->pricingServiceUrl, '/'),
                    rawurlencode($vehicleId),
                ),
            );

            $pricing = $pricingResponse->toArray();

            return new JsonResponse([
                'offerId' => sprintf('demo-%s', $vehicleId),
                'vehicleId' => $vehicleId,
                'status' => 'calculated',
                'pricing' => $pricing,
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
