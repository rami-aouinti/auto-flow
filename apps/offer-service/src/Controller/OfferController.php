<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\KeycloakTokenExchangeProvider;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\RequiresScope;
use InvalidArgumentException;
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


    #[RequiresScope('offers:quote')]
    #[Route('/api/offers/{vehicleId}/quotes', name: 'offer_create_quote', methods: ['POST'])]
    public function createQuote(string $vehicleId, Request $request): JsonResponse
    {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.offer-service', '1.0.0')
            ->spanBuilder('offer.quote.create')
            ->startSpan();
        $scope = $span->activate();

        try {
            $payload = $request->toArray();
            $pricingPayload = [
                'vehicleId' => $vehicleId,
                'vehiclePrice' => $this->requiredString($payload, 'vehiclePrice'),
                'downPayment' => $this->requiredString($payload, 'downPayment'),
                'residualValue' => $this->requiredString($payload, 'residualValue'),
                'currency' => $this->requiredString($payload, 'currency'),
                'durationMonths' => $this->requiredInteger($payload, 'durationMonths'),
                'annualInterestRateBasisPoints' => $this->requiredInteger(
                    $payload,
                    'annualInterestRateBasisPoints',
                ),
            ];

            $pricingToken = $this->pricingTokenExchange->exchangeForPricing(
                $this->requireBearerToken($request),
            );
            $pricingResponse = $this->httpClient->request(
                'POST',
                rtrim($this->pricingServiceUrl, '/') . '/api/pricing/quotes',
                [
                    'auth_bearer' => $pricingToken,
                    'headers' => [
                        'X-Tenant-ID' => $this->tenantContext->requireTenantId(),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $pricingPayload,
                ],
            );

            $response = new JsonResponse(
                $pricingResponse->toArray(false),
                $pricingResponse->getStatusCode(),
            );
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'error' => 'invalid_offer_quote_request',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $exception) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($exception);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a non-empty string.', $field));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredInteger(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an integer.', $field));
        }

        return $value;
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
