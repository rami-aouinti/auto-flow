<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Application\DTO\PricingQuoteRequest;
use App\Application\Port\Out\PricingGatewayInterface;
use App\Domain\ValueObject\PricingSnapshot;
use App\Security\KeycloakTokenExchangeProvider;
use InvalidArgumentException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpPricingGateway implements PricingGatewayInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private KeycloakTokenExchangeProvider $tokenExchange,
        private string $pricingServiceUrl,
    ) {
    }

    public function createQuote(PricingQuoteRequest $request, string $subjectAccessToken): PricingSnapshot
    {
        $pricingToken = $this->tokenExchange->exchangeForPricing($subjectAccessToken);
        $response = $this->httpClient->request(
            'POST',
            rtrim($this->pricingServiceUrl, '/') . '/api/pricing/quotes',
            [
                'auth_bearer' => $pricingToken,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Tenant-ID' => $request->tenantId,
                ],
                'json' => [
                    'vehicleId' => $request->vehicleId,
                    'vehiclePrice' => $request->vehiclePrice,
                    'downPayment' => $request->downPayment,
                    'residualValue' => $request->residualValue,
                    'currency' => $request->currency,
                    'durationMonths' => $request->durationMonths,
                    'annualInterestRateBasisPoints' => $request->annualInterestRateBasisPoints,
                ],
            ],
        );

        $payload = $response->toArray();

        return new PricingSnapshot(
            quoteId: self::requiredString($payload, 'quoteId'),
            vehicleId: self::requiredString($payload, 'vehicleId'),
            monthlyAmount: self::requiredString($payload, 'monthlyAmount'),
            totalInstallmentsAmount: self::requiredString($payload, 'totalInstallmentsAmount'),
            totalCostIncludingDownPayment: self::requiredString($payload, 'totalCostIncludingDownPayment'),
            residualValue: self::requiredString($payload, 'residualValue'),
            currency: self::requiredString($payload, 'currency'),
            durationMonths: self::requiredInteger($payload, 'durationMonths'),
            annualInterestRateBasisPoints: self::requiredInteger($payload, 'annualInterestRateBasisPoints'),
            calculationVersion: self::requiredString($payload, 'calculationVersion'),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Pricing response field "%s" is missing or invalid.', $field));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $payload */
    private static function requiredInteger(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Pricing response field "%s" is missing or invalid.', $field));
        }

        return $value;
    }
}
