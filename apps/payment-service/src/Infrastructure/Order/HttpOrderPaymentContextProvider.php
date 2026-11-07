<?php

declare(strict_types=1);

namespace App\Infrastructure\Order;

use App\Application\Port\OrderPaymentContextProviderInterface;
use DomainException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class HttpOrderPaymentContextProvider implements OrderPaymentContextProviderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $orderServiceUrl,
        private string $sharedSecret,
    ) {
    }

    public function getPayableContext(string $tenantId, string $orderId): array
    {
        if ($this->sharedSecret === '') {
            throw new \RuntimeException('ORDER_PAYMENT_SHARED_SECRET is not configured.');
        }
        try {
            $response = $this->http->request('GET', rtrim($this->orderServiceUrl, '/') . '/internal/orders/' . rawurlencode($orderId) . '/payment-context', [
                'headers' => [
                    'X-Autoflow-Service-Token' => $this->sharedSecret,
                    'X-Tenant-ID' => $tenantId,
                    'Accept' => 'application/json',
                ],
                'timeout' => 3.0,
                'max_duration' => 5.0,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
            if ($status === 404 || $status === 409) {
                throw new DomainException((string) ($payload['detail'] ?? 'Order is not payable.'));
            }
            if ($status === 403) {
                throw new \RuntimeException('Order service authentication failed.');
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Order service is temporarily unavailable.');
            }
            if (($payload['tenantId'] ?? null) !== $tenantId
                || ($payload['orderId'] ?? null) !== $orderId
                || ($payload['status'] ?? null) !== 'accepted'
                || !is_int($payload['amountMinor'] ?? null)
                || !is_string($payload['currency'] ?? null)) {
                throw new DomainException('Order payment context is invalid or not payable.');
            }
            return $payload;
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new \RuntimeException('Could not verify the order before payment creation.', 0, $exception);
        }
    }
}
