<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CalculateQuote\CalculateQuoteCommand;
use App\Application\CalculateQuote\CalculateQuoteHandler;
use App\Application\DeletePricingQuote\DeletePricingQuoteHandler;
use App\Application\GetPricingQuote\GetPricingQuoteHandler;
use App\Application\ListPricingQuotes\ListPricingQuotesHandler;
use App\Domain\Model\PricingQuote;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\RequiresScope;
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

    #[RequiresScope('pricing:quote')]
    #[Route('/api/pricing/quotes', name: 'pricing_calculate_quote', methods: ['POST'])]
    public function calculate(
        Request $request,
        CalculateQuoteHandler $handler,
        TenantContext $tenantContext,
    ): JsonResponse {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.pricing-service', '1.0.0')
            ->spanBuilder('pricing.calculate')
            ->startSpan();

        $scope = $span->activate();

        try {
            $payload = $request->toArray();
            $command = new CalculateQuoteCommand(
                tenantId: $tenantContext->requireTenantId(),
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


    #[RequiresScope('pricing:quote')]
    #[Route('/api/pricing/quotes/{quoteId}', name: 'pricing_get_quote', methods: ['GET'])]
    public function getQuote(
        string $quoteId,
        GetPricingQuoteHandler $handler,
        TenantContext $tenantContext,
    ): JsonResponse {
        $quote = $handler($tenantContext->requireTenantId(), $quoteId);

        if ($quote === null) {
            return new JsonResponse(['error' => 'quote_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->quoteToArray($quote));
    }

    #[RequiresScope('pricing:quote')]
    #[Route('/api/pricing/quotes', name: 'pricing_list_quotes', methods: ['GET'])]
    public function listQuotes(
        Request $request,
        ListPricingQuotesHandler $handler,
        TenantContext $tenantContext,
    ): JsonResponse {
        $limit = filter_var($request->query->get('limit', '50'), FILTER_VALIDATE_INT);
        $offset = filter_var($request->query->get('offset', '0'), FILTER_VALIDATE_INT);

        if (!is_int($limit) || !is_int($offset)) {
            return new JsonResponse([
                'error' => 'invalid_pagination',
                'message' => 'Limit and offset must be integers.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $quotes = $handler($tenantContext->requireTenantId(), $limit, $offset);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'error' => 'invalid_pagination',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'items' => array_map($this->quoteToArray(...), $quotes),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    #[RequiresScope('pricing:quote')]
    #[Route('/api/pricing/quotes/{quoteId}', name: 'pricing_delete_quote', methods: ['DELETE'])]
    public function deleteQuote(
        string $quoteId,
        DeletePricingQuoteHandler $handler,
        TenantContext $tenantContext,
    ): JsonResponse {
        $deleted = $handler($tenantContext->requireTenantId(), $quoteId);

        if (!$deleted) {
            return new JsonResponse(['error' => 'quote_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, int|string>
     */
    private function quoteToArray(PricingQuote $quote): array
    {
        return [
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
        ];
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
