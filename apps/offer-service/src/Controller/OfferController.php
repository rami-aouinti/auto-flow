<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\KeycloakTokenExchangeProvider;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\RequiresScope;
use LogicException;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
            $pricingToken = $this->pricingTokenExchange->exchangeForPricing(
                $this->requireBearerToken($request),
            );

            $pricingResponse = $this->httpClient->request(
                'GET',
                sprintf(
                    '%s/api/pricing/%s',
                    rtrim($this->pricingServiceUrl, '/'),
                    rawurlencode($vehicleId),
                ),
                [
                    'auth_bearer' => $pricingToken,
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

    private function requireBearerToken(Request $request): string
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        $authorization = $request->headers->get('Authorization', '');
        if (!is_string($authorization) || preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            throw new LogicException('A bearer access token is required for downstream token exchange.');
        }

        return trim($matches[1]);
    }
}
