<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Tests\Unit;

use AutoFlow\Notification\Infrastructure\Messaging\MercureEvent;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MercureEventTest extends TestCase
{
    public function testMapsOnlySafeSummaryToTenantPrivateTopic(): void
    {
        $result = MercureEvent::fromDelivery(
            json_encode([
                'eventId' => 'evt-123',
                'eventType' => 'OrderAccepted',
                'tenantId' => 'tenant-demo',
                'occurredAt' => '2026-11-14T10:00:00Z',
                'aggregate' => ['type' => 'Order', 'id' => 'order-123'],
                'data' => ['email' => 'private@example.test', 'phone' => '+4912345'],
            ], JSON_THROW_ON_ERROR),
            ['producer' => 'order-service'],
            'OrderAccepted',
            'evt-123',
            'https://autoflow.local/tenants',
        );

        self::assertSame('https://autoflow.local/tenants/tenant-demo/events', $result['topic']);
        self::assertSame('evt-123', $result['data']['id']);
        self::assertSame('OrderAccepted', $result['data']['type']);
        self::assertSame('tenant-demo', $result['data']['tenantId']);
        self::assertSame('Order', $result['data']['aggregateType']);
        self::assertSame('order-123', $result['data']['aggregateId']);
        self::assertSame('order-service', $result['data']['producer']);
        self::assertArrayNotHasKey('data', $result['data']);
        self::assertArrayNotHasKey('email', $result['data']);
        self::assertArrayNotHasKey('phone', $result['data']);
    }

    public function testResolvesTenantFromAmqpHeadersWhenEnvelopeHasNoTenant(): void
    {
        $result = MercureEvent::fromDelivery(
            json_encode(['eventType' => 'InventoryUpdated'], JSON_THROW_ON_ERROR),
            ['tenant_id' => 'tenant:one', 'producer' => 'inventory-service'],
            'InventoryUpdated',
            'evt-456',
            'https://autoflow.local/tenants/',
        );

        self::assertSame('https://autoflow.local/tenants/tenant%3Aone/events', $result['topic']);
        self::assertSame('inventory-service', $result['data']['producer']);
    }

    public function testRejectsEventsWithoutTenantInsteadOfBroadcasting(): void
    {
        $this->expectException(UnexpectedValueException::class);
        MercureEvent::fromDelivery(
            json_encode(['eventType' => 'OrderCreated'], JSON_THROW_ON_ERROR),
            [],
            'OrderCreated',
            'evt-789',
            'https://autoflow.local/tenants',
        );
    }

    public function testRejectsInvalidTenantIdentifiers(): void
    {
        $this->expectException(UnexpectedValueException::class);
        MercureEvent::fromDelivery(
            json_encode(['tenantId' => '../other-tenant'], JSON_THROW_ON_ERROR),
            [],
            'OrderCreated',
            'evt-789',
            'https://autoflow.local/tenants',
        );
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(\JsonException::class);
        MercureEvent::fromDelivery('{invalid-json', [], null, null, 'https://autoflow.local/tenants');
    }
}
