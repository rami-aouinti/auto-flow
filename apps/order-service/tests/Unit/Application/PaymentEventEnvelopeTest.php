<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\PaymentEventEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentEventEnvelopeTest extends TestCase
{
    private const EVENT_ID = '01936f5e-7b1a-7d42-9a6f-9b6d5c4e3f21';
    private const PAYMENT_ID = '01936f5e-7b1a-7d42-9a6f-9b6d5c4e3f22';
    private const ORDER_ID = '01936f5e-7b1a-7d42-9a6f-9b6d5c4e3f23';

    public function testItAcceptsAValidPaymentCapturedEvent(): void
    {
        $body = $this->body();

        $event = PaymentEventEnvelope::fromMessage($body, self::EVENT_ID, 'PaymentCaptured');

        self::assertSame(self::EVENT_ID, $event->eventId);
        self::assertSame('PaymentCaptured', $event->eventType);
        self::assertSame('tenant-demo', $event->tenantId);
        self::assertSame(self::PAYMENT_ID, $event->paymentId);
        self::assertSame(self::ORDER_ID, $event->orderId);
        self::assertSame(hash('sha256', $body), $event->payloadHash);
    }

    public function testItRejectsRabbitMetadataThatDoesNotMatchTheEnvelope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentEventEnvelope::fromMessage($this->body(), self::EVENT_ID, 'PaymentRefunded');
    }

    public function testItRejectsAnUnexpectedProducer(): void
    {
        $body = json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
        $body['producer'] = 'untrusted-service';

        $this->expectException(InvalidArgumentException::class);

        PaymentEventEnvelope::fromMessage(
            json_encode($body, JSON_THROW_ON_ERROR),
            self::EVENT_ID,
            'PaymentCaptured',
        );
    }

    public function testItRejectsAnInvalidOrderIdentifier(): void
    {
        $body = json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
        $body['data']['orderId'] = 'order-123';

        $this->expectException(InvalidArgumentException::class);

        PaymentEventEnvelope::fromMessage(
            json_encode($body, JSON_THROW_ON_ERROR),
            self::EVENT_ID,
            'PaymentCaptured',
        );
    }

    private function body(): string
    {
        return json_encode([
            'eventId' => self::EVENT_ID,
            'eventType' => 'PaymentCaptured',
            'schemaVersion' => 1,
            'producer' => 'payment-service',
            'aggregate' => ['type' => 'Payment', 'id' => self::PAYMENT_ID],
            'tenantId' => 'tenant-demo',
            'occurredAt' => '2026-11-14T02:00:00+00:00',
            'data' => [
                'paymentId' => self::PAYMENT_ID,
                'orderId' => self::ORDER_ID,
                'status' => 'captured',
                'amountMinor' => 129900,
                'currency' => 'EUR',
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
