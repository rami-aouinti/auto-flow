<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

use App\Application\OrderReferenceValidationException;
use App\Application\OrderUpstreamAuthorizationException;
use App\Application\OrderUpstreamUnavailableException;
use App\Application\Port\OrderReferenceValidatorInterface;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class HttpOrderReferenceValidator implements OrderReferenceValidatorInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $offerServiceUrl,
        private string $pricingServiceUrl,
        private string $catalogServiceUrl,
    ) {
    }

    public function validate(
        string $offerId,
        string $quoteId,
        string $vehicleId,
        string $tenantId,
        string $subjectAccessToken,
    ): array {
        foreach ([$offerId, $quoteId, $vehicleId, $tenantId] as $identifier) {
            if (trim($identifier) === '' || strlen($identifier) > 128) {
                throw new OrderReferenceValidationException('An upstream reference is invalid.');
            }
        }
        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $subjectAccessToken) !== 1) {
            throw new OrderReferenceValidationException('A verified user access token is required.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $subjectAccessToken,
            'X-Tenant-ID' => $tenantId,
            'Accept' => 'application/json',
        ];

        $offer = $this->getJson($this->offerServiceUrl, '/api/offers/' . rawurlencode($offerId), $headers);
        $quote = $this->getJson($this->pricingServiceUrl, '/api/pricing/quotes/' . rawurlencode($quoteId), $headers);
        $vehicle = $this->getJson($this->catalogServiceUrl, '/api/v1/vehicles/' . rawurlencode($vehicleId), $headers);

        if (($offer['offerId'] ?? null) !== $offerId
            || ($offer['quoteId'] ?? null) !== $quoteId
            || ($offer['vehicleId'] ?? null) !== $vehicleId
            || ($offer['status'] ?? null) !== 'accepted') {
            throw new OrderReferenceValidationException('The referenced offer is not accepted or does not match the order.');
        }

        $offerExpiry = $this->date($offer['expiresAt'] ?? null);
        if ($offerExpiry === null || $offerExpiry <= new DateTimeImmutable()) {
            throw new OrderReferenceValidationException('The referenced offer has expired or has no valid expiry date.');
        }

        if (($quote['quoteId'] ?? null) !== $quoteId
            || ($quote['vehicleId'] ?? null) !== $vehicleId
            || ($quote['expired'] ?? true) !== false) {
            throw new OrderReferenceValidationException('The referenced pricing quote is expired or does not match the order.');
        }

        $quoteExpiry = $this->date($quote['expiresAt'] ?? null);
        if ($quoteExpiry === null || $quoteExpiry <= new DateTimeImmutable()) {
            throw new OrderReferenceValidationException('The referenced pricing quote has expired or has no valid expiry date.');
        }

        if (($vehicle['variantId'] ?? null) !== $vehicleId || ($vehicle['status'] ?? null) !== 'published') {
            throw new OrderReferenceValidationException('The referenced vehicle is not published in the catalogue.');
        }

        return [
            'offerSnapshot' => $offer,
            'quoteSnapshot' => $quote,
            'vehicleSnapshot' => $vehicle,
        ];
    }

    /** @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function getJson(string $baseUrl, string $path, array $headers): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/') . $path, [
                'headers' => $headers,
                'timeout' => 3.0,
                'max_duration' => 5.0,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new OrderUpstreamAuthorizationException('The caller is not authorized to verify upstream order references.');
            }
            if ($status === 404 || $status === 410) {
                throw new OrderReferenceValidationException('An upstream order reference does not exist or is no longer valid.');
            }
            if ($status < 200 || $status >= 300) {
                throw new OrderUpstreamUnavailableException('An upstream service is temporarily unavailable for order validation.');
            }
            $payload = $response->toArray(false);
            if (!is_array($payload) || array_is_list($payload) || $payload === []) {
                throw new OrderReferenceValidationException('An upstream service returned an invalid reference representation.');
            }
            return $payload;
        } catch (OrderReferenceValidationException|OrderUpstreamAuthorizationException|OrderUpstreamUnavailableException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new OrderUpstreamUnavailableException('An upstream service could not be reached to validate the order.');
        }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
