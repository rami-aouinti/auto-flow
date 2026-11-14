<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final readonly class CrmReferenceValidator
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $customerServiceUrl,
        private string $offerServiceUrl,
        private string $pricingServiceUrl,
        private string $catalogServiceUrl,
    ) {
    }

    public function validate(
        string $customerId,
        ?string $offerId,
        ?string $quoteId,
        ?string $vehicleId,
        string $tenantId,
        string $accessToken,
    ): void {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'X-Tenant-ID' => $tenantId,
            'Accept' => 'application/json',
        ];

        $customer = $this->getJson(
            $this->customerServiceUrl,
            '/api/v1/customers/' . rawurlencode($customerId),
            $headers,
        );
        if (($customer['customer_id'] ?? null) !== $customerId
            || ($customer['status'] ?? null) !== 'active'
            || ($customer['archived_at'] ?? null) !== null) {
            throw new \DomainException('The referenced customer is not active or does not belong to this tenant.');
        }

        $referencesProvided = $offerId !== null || $quoteId !== null || $vehicleId !== null;
        if (!$referencesProvided) {
            return;
        }
        if ($offerId === null || $quoteId === null || $vehicleId === null) {
            throw new \DomainException('offerId, quoteId and vehicleId must be supplied together.');
        }

        $offer = $this->getJson(
            $this->offerServiceUrl,
            '/api/offers/' . rawurlencode($offerId),
            $headers,
        );
        if (($offer['offerId'] ?? null) !== $offerId
            || ($offer['quoteId'] ?? null) !== $quoteId
            || ($offer['vehicleId'] ?? null) !== $vehicleId
            || !in_array($offer['status'] ?? null, ['published', 'accepted'], true)) {
            throw new \DomainException('The referenced offer is not available or does not match the application.');
        }

        $quote = $this->getJson(
            $this->pricingServiceUrl,
            '/api/pricing/quotes/' . rawurlencode($quoteId),
            $headers,
        );
        if (($quote['quoteId'] ?? null) !== $quoteId
            || ($quote['vehicleId'] ?? null) !== $vehicleId
            || ($quote['expired'] ?? true) !== false) {
            throw new \DomainException('The referenced pricing quote is expired or does not match the application.');
        }

        $vehicle = $this->getJson(
            $this->catalogServiceUrl,
            '/api/v1/vehicles/' . rawurlencode($vehicleId),
            $headers,
        );
        if (($vehicle['variantId'] ?? null) !== $vehicleId || ($vehicle['status'] ?? null) !== 'published') {
            throw new \DomainException('The referenced vehicle is not published in the catalogue.');
        }
    }

    /** @param array<string,string> $headers
     *  @return array<string,mixed>
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
                throw new AccessDeniedHttpException('The caller is not authorized to validate CRM references.');
            }
            if ($status === 404 || $status === 410) {
                throw new \DomainException('A referenced customer, offer, quote or vehicle does not exist or is no longer valid.');
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('An upstream service is temporarily unavailable.');
            }
            $payload = $response->toArray(false);
            if (!is_array($payload) || array_is_list($payload) || $payload === []) {
                throw new \RuntimeException('An upstream service returned an invalid response.');
            }
            return $payload;
        } catch (\DomainException|\RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new \RuntimeException('An upstream service could not be reached to validate CRM references.');
        }
    }
}
