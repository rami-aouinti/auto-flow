<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\IntegrationEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class IntegrationEventTest extends TestCase
{
    public function testParsesPaymentCapturedEnvelope(): void
    {
        $event = IntegrationEvent::fromJson(json_encode($this->envelope(
            'PaymentCaptured',
            'payment-service',
        ), JSON_THROW_ON_ERROR));

        self::assertSame('PaymentCaptured', $event->eventType);
        self::assertSame('tenant-demo', $event->tenantId);
        self::assertSame('2026-11-07T10:00:00+00:00', $event->occurredAt->format(DATE_ATOM));
    }

    public function testParsesContractSignedEnvelope(): void
    {
        $event = IntegrationEvent::fromJson(json_encode($this->envelope(
            'contract.signed.v1',
            'contract-service',
        ), JSON_THROW_ON_ERROR));

        self::assertSame('contract.signed.v1', $event->eventType);
        self::assertSame('contract-service', $event->producer);
    }

    public function testRejectsUnsupportedSchemaVersion(): void
    {
        $envelope = $this->envelope('PaymentCaptured', 'payment-service');
        $envelope['schemaVersion'] = 2;

        $this->expectException(InvalidArgumentException::class);
        IntegrationEvent::fromJson(json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    public function testRejectsEventProducerMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntegrationEvent::fromJson(json_encode(
            $this->envelope('PaymentCaptured', 'contract-service'),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testRejectsUnsupportedEventType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntegrationEvent::fromJson(json_encode(
            $this->envelope('PaymentAuthorized', 'payment-service'),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testRejectsNonUuidOrderId(): void
    {
        $envelope = $this->envelope('PaymentCaptured', 'payment-service');
        $envelope['data']['orderId'] = 'order-not-a-uuid';

        $this->expectException(InvalidArgumentException::class);
        IntegrationEvent::fromJson(json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function envelope(string $type, string $producer): array
    {
        return [
            'eventId' => Uuid::v7()->toRfc4122(),
            'eventType' => $type,
            'schemaVersion' => 1,
            'producer' => $producer,
            'aggregate' => ['type' => 'Payment', 'id' => Uuid::v7()->toRfc4122()],
            'tenantId' => 'tenant-demo',
            'occurredAt' => '2026-11-07T10:00:00+00:00',
            'data' => ['orderId' => Uuid::v7()->toRfc4122()],
        ];
    }
}
