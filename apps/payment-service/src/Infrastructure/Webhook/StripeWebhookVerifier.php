<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use RuntimeException;

final readonly class StripeWebhookVerifier
{
    public function __construct(private string $webhookSecret)
    {
    }

    public function verify(string $payload, string $signatureHeader, int $toleranceSeconds = 300): void
    {
        if ($this->webhookSecret === '') {
            throw new RuntimeException('Stripe webhook secret is not configured.');
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key][] = $value;
        }
        $timestamp = isset($parts['t'][0]) ? filter_var($parts['t'][0], FILTER_VALIDATE_INT) : false;
        if ($timestamp === false || abs(time() - $timestamp) > $toleranceSeconds) {
            throw new RuntimeException('Webhook timestamp is invalid or expired.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }
        throw new RuntimeException('Webhook signature is invalid.');
    }
}
