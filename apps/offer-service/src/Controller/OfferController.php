<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\KeycloakClientCredentialsTokenProvider;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\RequiresScope;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
#[AsController]
final readonly class OfferController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $pricingServiceUrl,
        private KeycloakTokenExchangeProvider $pricingTokenExchange,
        private TenantContext $tenantContext,
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

    #[RequiresScope('offers:read')]
    #[Route('/api/offers/{vehicleId}', name: 'offer_show', methods: ['GET'])]
    public function show(string $vehicleId, Request $request): JsonResponse
    {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.offer-service', '1.0.0')
            ->spanBuilder('offer.compose')
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttribute('vehicle.id', $vehicleId);

            $pricingResponse = $this->httpClient->request(
                'GET',
                sprintf(
                    '%s/api/pricing/%s',
                    rtrim($this->pricingServiceUrl, '/'),
                    rawurlencode($vehicleId),
                ),
                [
                    'auth_bearer' => $this->pricingTokenExchange->exchangeForPricing($this->requireBearerToken($request)),
                    'headers' => ['X-Tenant-ID' => $this->tenantContext->requireTenantId()],
                ],
            );

            $pricing = $pricingResponse->toArray();

            return new JsonResponse([
                'offerId' => sprintf('demo-%s', $vehicleId),
                'vehicleId' => $vehicleId,
                'status' => 'calculated',
                'pricing' => $pricing,
            ]);
        } catch (Throwable $exception) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($exception);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
