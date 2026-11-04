<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CalculateQuote\CalculateQuoteCommand;
use App\Application\CalculateQuote\CalculateQuoteHandler;
use InvalidArgumentException;
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
    #[Route('/health', name: 'pricing_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'pricing-service',
            'status' => 'ok',
        ]);
    }

    #[Route('/api/pricing/quotes', name: 'pricing_calculate_quote', methods: ['POST'])]
    public function calculate(Request $request, CalculateQuoteHandler $handler): JsonResponse
    {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.pricing-service', '1.0.0')
            ->spanBuilder('pricing.calculate')
            ->startSpan();

        $scope = $span->activate();

        try {
            $payload = $request->toArray();
            $command = new CalculateQuoteCommand(
                vehicleId: $this->requiredString($payload, 'vehicleId'),
                vehiclePrice: $this->requiredString($payload, 'vehiclePrice'),
                downPayment: $this->requiredString($payload, 'downPayment'),
                residualValue: $this->requiredString($payload, 'residualValue'),
                currency: $this->requiredString($payload, 'currency'),
                durationMonths: $this->requiredInteger($payload, 'durationMonths'),
                annualRateBps: $this->requiredInteger($payload, 'annualInterestRateBasisPoints'),
            );

            $quote = $handler($command);
            $span->setAttribute('vehicle.id', $quote->vehicleId);
            $span->setAttribute('pricing.duration_months', $quote->durationMonths);
            $span->setAttribute('pricing.currency', $quote->monthlyAmount->currency);

            $response = new JsonResponse([
                'vehicleId' => $quote->vehicleId,
                'monthlyAmount' => $quote->monthlyAmount->toDecimal(),
                'totalInstallmentsAmount' => $quote->totalInstallmentsAmount->toDecimal(),
                'totalCostIncludingDownPayment' => $quote->totalCostIncludingDownPayment->toDecimal(),
                'residualValue' => $quote->residualValue->toDecimal(),
                'currency' => $quote->monthlyAmount->currency,
                'durationMonths' => $quote->durationMonths,
                'annualInterestRateBasisPoints' => $quote->annualInterestRateBasisPoints,
                'calculationVersion' => $quote->calculationVersion,
            ]);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'error' => 'invalid_pricing_request',
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
     * Legacy endpoint retained for the existing Offer demonstration.
     *
     * @deprecated Use POST /api/pricing/quotes with explicit lease terms.
     */
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
