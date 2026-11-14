<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\PaymentEventEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentEventEnvelopeTest extends TestCase
{
    private const EVENT_ID = 'b7e9f3d4-1f6c-4a5a-8f31-9d4e2c6a7b10';
    private const PAYMENT_ID = 'c4f0f45a-9f7a-4c87-9db0-9c5db6b7b001';
    private const ORDER_ID = 'd6f0f45a-9f7a-4c87-9db0-9c5db6b7b002';

    public function testParsesCapturedPaymentEnvelopeAndFingerprint(): void
    {
        $body = $this->body('PaymentCaptured', [
            'paymentId' => self::PAYMENT_ID,
            'orderId' => self::ORDER_ID,
            'amountMinor' => 129900,
            'currency' => 'EUR',
        ]);

        $event = PaymentEventEnvelope::fromMessage($body, self::EVENT_ID, 'PaymentCaptured');

        self::assertSame(self::EVENT_ID, $event->eventId);
        self::assertSame(self::PAYMENT_ID, $event->paymentId);
        self::assertSame(self::ORDER_ID, $event->orderId);
        self::assertSame('tenant-demo', $event->tenantId);
        self::assertSame(hash('sha256', $body), $event->payloadHash);
    }

    public function testParsesRefundEnvelope(): void
    {
        $body = $this->body('PaymentPartiallyRefunded', [
            'paymentId' => self::PAYMENT_ID,
            'orderId' => self::ORDER_ID,
            'refundAmountMinor' => 2500,
            'currency' => 'EUR',
        ]);

        $event = PaymentEventEnvelope::fromMessage($body, self::EVENT_ID, 'PaymentPartiallyRefunded');

        self::assertSame(2500, $event->data['refundAmountMinor']);
    }

    public function testRejectsRabbitMetadataMismatch(): void
    {
        $body = $this->body('PaymentCaptured', [
            'paymentId' => self::PAYMENT_ID,
            'orderId' => self::ORDER_ID,
            'amountMinor' => 129900,
            'currency' => 'EUR',
        ]);

        $this->expectException(InvalidArgumentException::class);
        PaymentEventEnvelope::fromMessage($body, 'another-event-id', 'PaymentCaptured');
    }

    public function testRejectsUnsupportedProducerAndEventType(): void
    {
        $body = json_encode([
            'eventId' => self::EVENT_ID,
            'eventType' => 'PaymentCaptured',
            'schemaVersion' => 1,
            'producer' => 'unknown-service',
            'tenantId' => 'tenant-demo',
            'data' => ['paymentId' => self::PAYMENT_ID, 'orderId' => self::ORDER_ID, 'amountMinor' => 100, 'currency' => 'EUR'],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        PaymentEventEnvelope::fromMessage($body, self::EVENT_ID, 'PaymentCaptured');
    }

    public function testRejectsInvalidCapturedAmount(): void
    {
        $body = $this->body('PaymentCaptured', [
            'paymentId' => self::PAYMENT_ID,
            'orderId' => self::ORDER_ID,
            'amountMinor' => 0,
            'currency' => 'EUR',
        ]);

        $this->expectException(InvalidArgumentException::class);
        PaymentEventEnvelope::fromMessage($body, self::EVENT_ID, 'PaymentCaptured');
    }

    /** @param array<string, mixed> $data */
    private function body(string $type, array $data): string
    {
        return json_encode([
            'eventId' => self::EVENT_ID,
            'eventType' => $type,
            'schemaVersion' => 1,
            'producer' => 'payment-service',
            'tenantId' => 'tenant-demo',
            'occurredAt' => '2026-11-14T00:00:00+00:00',
            'data' => $data,
        ], JSON_THROW_ON_ERROR);
    }
}
