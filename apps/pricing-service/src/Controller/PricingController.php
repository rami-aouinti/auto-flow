<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CalculateQuote\CalculateQuoteCommand;
use App\Application\CalculateQuote\CalculateQuoteHandler;
use App\Infrastructure\Persistence\PostgresConnection;
use App\Observability\PricingMetrics;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\RequiresScope;
use InvalidArgumentException;
use JsonException;
use LogicException;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
#[AsController]
final class PricingController
{
    #[Route('/ready', name: 'pricing_readiness', methods: ['GET'])]
    public function readiness(PostgresConnection $connection): JsonResponse
    {
        try {
            $connection->get()->query('SELECT 1')->fetchColumn();
        } catch (Throwable) {
            return new JsonResponse([
                'service' => 'pricing-service',
                'status' => 'not_ready',
                'dependencies' => ['postgresql' => 'unavailable'],
            ], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse([
            'service' => 'pricing-service',
            'status' => 'ready',
            'dependencies' => ['postgresql' => 'ok'],
        ]);
    }

    #[Route('/health', name: 'pricing_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'pricing-service',
            'status' => 'ok',
        ]);
    }

    #[RequiresScope('pricing:quote')]
    #[Route('/api/pricing/quotes', name: 'pricing_calculate_quote', methods: ['POST'])]
    public function calculate(
        Request $request,
        CalculateQuoteHandler $handler,
        TenantContext $tenantContext,
        PricingMetrics $metrics,
    ): JsonResponse {
        $startedAt = hrtime(true);
        $metricResult = 'error';
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.pricing-service', '1.0.0')
            ->spanBuilder('pricing.calculate')
            ->startSpan();

        $scope = $span->activate();

        try {
            $payload = $request->toArray();
            $principal = $this->authenticatedPrincipal($request);
            $command = new CalculateQuoteCommand(
                tenantId: $tenantContext->requireTenantId(),
                createdBy: $principal->subject,
                vehicleId: $this->requiredString($payload, 'vehicleId'),
                vehiclePrice: $this->requiredString($payload, 'vehiclePrice'),
                downPayment: $this->requiredString($payload, 'downPayment'),
                residualValue: $this->requiredString($payload, 'residualValue'),
                currency: $this->requiredString($payload, 'currency'),
                durationMonths: $this->requiredInteger($payload, 'durationMonths'),
                annualRateBps: $this->requiredInteger($payload, 'annualInterestRateBasisPoints'),
            );

            $quote = $handler($command);
            $metricResult = 'success';
            $span->setAttribute('vehicle.id', $quote->vehicleId);
            $span->setAttribute('pricing.duration_months', $quote->durationMonths);
            $span->setAttribute('pricing.currency', $quote->monthlyAmount->currency);

            $response = new JsonResponse([
                'quoteId' => $quote->quoteId,
                'vehicleId' => $quote->vehicleId,
                'monthlyAmount' => $quote->monthlyAmount->toDecimal(),
                'totalInstallmentsAmount' => $quote->totalInstallmentsAmount->toDecimal(),
                'totalCostIncludingDownPayment' => $quote->totalCostIncludingDownPayment->toDecimal(),
                'residualValue' => $quote->residualValue->toDecimal(),
                'currency' => $quote->monthlyAmount->currency,
                'durationMonths' => $quote->durationMonths,
                'annualInterestRateBasisPoints' => $quote->annualInterestRateBasisPoints,
                'calculationVersion' => $quote->calculationVersion,
                'vehiclePrice' => $quote->vehiclePrice?->toDecimal(),
                'downPayment' => $quote->downPayment?->toDecimal(),
                'createdAt' => $quote->createdAt->format(DATE_ATOM),
                'expiresAt' => $quote->expiresAt->format(DATE_ATOM),
                'policyId' => $quote->policyId,
                'policyVersion' => $quote->policyVersion,
                'expired' => $quote->isExpired(),
            ]);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (JsonException) {
            $metricResult = 'invalid_request';
            return new JsonResponse([
                'error' => 'invalid_json',
                'message' => 'Request body must contain valid JSON.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        } catch (InvalidArgumentException $exception) {
            $metricResult = 'invalid_request';
            return new JsonResponse([
                'error' => 'invalid_pricing_request',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $exception) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($exception);

            throw $exception;
        } finally {
            $metrics->recordCalculation((hrtime(true) - $startedAt) / 1_000_000, $metricResult);
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Legacy endpoint retained for the existing Offer demonstration.
     *
     * @deprecated Use POST /api/pricing/quotes with explicit lease terms.
     */
    #[RequiresScope('pricing:quote')]
    #[Route('/api/pricing/{vehicleId}', name: 'pricing_quote_legacy', methods: ['GET'])]
    public function legacyQuote(string $vehicleId): JsonResponse
    {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.pricing-service', '1.0.0')
            ->spanBuilder('pricing.calculate.legacy')
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttribute('vehicle.id', $vehicleId);

            $response = new JsonResponse([
                'vehicleId' => $vehicleId,
                'monthlyAmount' => 329.00,
                'currency' => 'EUR',
                'durationMonths' => 48,
                'calculation' => 'demo-only',
            ]);
            $response->headers->set('Deprecation', 'true');
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (Throwable $exception) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($exception);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function authenticatedPrincipal(Request $request): AuthenticatedPrincipal
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        return $principal;
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
}
