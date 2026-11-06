<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Application\DTO\PricingQuoteProxyResponse;
use App\Application\Port\Out\PricingQuoteProxyInterface;
use App\Security\KeycloakTokenExchangeProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpPricingQuoteProxy implements PricingQuoteProxyInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private KeycloakTokenExchangeProvider $tokenExchange,
        private string $pricingServiceUrl,
    ) {
    }

    public function requestQuote(
        string $vehicleId,
        array $payload,
        string $subjectAccessToken,
        string $tenantId,
    ): PricingQuoteProxyResponse {
        $pricingToken = $this->tokenExchange->exchangeForPricing($subjectAccessToken);
        $response = $this->httpClient->request(
            'POST',
            rtrim($this->pricingServiceUrl, '/') . '/api/pricing/quotes',
            [
                'auth_bearer' => $pricingToken,
                'headers' => [
                    'X-Tenant-ID' => $tenantId,
                    'Content-Type' => 'application/json',
                ],
                'json' => ['vehicleId' => $vehicleId] + $payload,
            ],
        );

        return new PricingQuoteProxyResponse(
            $response->getStatusCode(),
            $response->getContent(false),
        );
    }
}
