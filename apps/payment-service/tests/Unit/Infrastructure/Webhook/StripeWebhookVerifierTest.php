<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Webhook;

use App\Infrastructure\Webhook\StripeWebhookVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StripeWebhookVerifierTest extends TestCase
{
    public function testAcceptsValidSignature(): void
    {
        $secret = 'whsec_test_secret';
        $payload = '{"id":"evt_test","type":"payment_intent.succeeded"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        (new StripeWebhookVerifier($secret))->verify($payload, 't=' . $timestamp . ',v1=' . $signature);

        self::assertTrue(true);
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->expectException(RuntimeException::class);

        (new StripeWebhookVerifier('whsec_test_secret'))->verify(
            '{"id":"evt_test"}',
            't=' . time() . ',v1=invalid',
        );
    }

    public function testRejectsExpiredSignature(): void
    {
        $this->expectException(RuntimeException::class);

        (new StripeWebhookVerifier('whsec_test_secret'))->verify(
            '{"id":"evt_test"}',
            't=' . (time() - 600) . ',v1=invalid',
        );
    }
}
