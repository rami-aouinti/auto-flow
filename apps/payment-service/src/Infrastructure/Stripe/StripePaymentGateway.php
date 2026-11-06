<?php

declare(strict_types=1);

namespace App\\Infrastructure\\Stripe;

use App\\Application\\Port\\PaymentGatewayInterface;
use Symfony\\Contracts\\HttpClient\\HttpClientInterface;
use Symfony\\Contracts\\HttpClient\\Exception\\TransportExceptionInterface;
use RuntimeException;
use Throwable;

final readonly class StripePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $secretKey,
        private string $apiBase,
    ) {
        if ($this->secretKey === '') {
            throw new RuntimeException('STRIPE_SECRET_KEY must be configured.');
        }
    }

    public function createIntent(int $amountMinor, string $currency, string $paymentId, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/payment_intents', [
            'amount' => $amountMinor,
            'currency' => strtolower($currency),
            'capture_method' => 'manual',
            'metadata[payment_id]' => $paymentId,
        ], $idempotencyKey);
    }

    public function capture(string $providerPaymentId, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/payment_intents/' . rawurlencode($providerPaymentId) . '/capture', [], $idempotencyKey);
    }

    public function cancel(string $providerPaymentId, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/payment_intents/' . rawurlencode($providerPaymentId) . '/cancel', [], $idempotencyKey);
    }

    public function refund(string $providerPaymentId, int $amountMinor, string $idempotencyKey): array
    {
        return $this->request('POST', '/v1/refunds', [
            'payment_intent' => $providerPaymentId,
            'amount' => $amountMinor,
        ], $idempotencyKey);
    }

    /** @param array<string,int|string> $form
     *  @return array<string,mixed>
     */
    private function request(string $method, string $path, array $form, string $idempotencyKey): array
    {
        try {
            $response = $this->http->request($method, rtrim($this->apiBase, '/') . $path, [
                'auth_bearer' => $this->secretKey,
                'headers' => ['Idempotency-Key' => $idempotencyKey],
                'body' => $form,
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            if ($status < 200 || $status >= 300 || !is_array($data) || isset($data['error'])) {
                $message = $data['error']['message'] ?? 'Payment provider rejected the request.';
                throw new RuntimeException((string) $message);
            }
            return $data;
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException('Payment provider is temporarily unavailable.', 0, $exception);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Payment provider request failed.', 0, $exception);
        }
    }
}
