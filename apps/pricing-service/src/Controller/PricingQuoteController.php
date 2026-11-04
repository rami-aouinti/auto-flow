<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\DeletePricingQuote\DeletePricingQuoteHandler;
use App\Application\GetPricingQuote\GetPricingQuoteHandler;
use App\Application\ListPricingQuotes\ListPricingQuotesHandler;
use App\Domain\Model\PricingQuote;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\RequiresScope;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class PricingQuoteController
{
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

        $response = new JsonResponse($this->quoteToArray($quote));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
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

        $response = new JsonResponse([
            'items' => array_map($this->quoteToArray(...), $quotes),
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[RequiresScope('pricing:quote')]
    #[Route('/api/pricing/quotes/{quoteId}', name: 'pricing_delete_quote', methods: ['DELETE'])]
    public function deleteQuote(
        string $quoteId,
        DeletePricingQuoteHandler $handler,
        TenantContext $tenantContext,
    ): JsonResponse {
        if (!$handler($tenantContext->requireTenantId(), $quoteId)) {
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
}
