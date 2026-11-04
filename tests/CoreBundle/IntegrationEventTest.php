<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Messaging\IntegrationEvent;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class IntegrationEventTest extends TestCase
{
    public function testEventMetadataIsSerializable(): void
    {
        $event = new IntegrationEvent(
            eventId: '550e8400-e29b-41d4-a716-446655440000',
            eventName: 'pricing.quote.calculated',
            aggregateId: 'quote-123',
            occurredAt: new DateTimeImmutable('2026-11-04T00:00:00+00:00'),
            schemaVersion: 1,
            payload: ['currency' => 'EUR'],
            correlationId: 'request-123',
            causationId: 'command-456',
        );

        $serialized = $event->jsonSerialize();

        self::assertSame('pricing.quote.calculated', $serialized['eventName']);
        self::assertSame('quote-123', $serialized['aggregateId']);
        self::assertSame(1, $serialized['schemaVersion']);
        self::assertSame('request-123', $serialized['correlationId']);
        self::assertSame('command-456', $serialized['causationId']);
        self::assertSame(['currency' => 'EUR'], $serialized['payload']);
        self::assertSame('2026-11-04T00:00:00+00:00', $serialized['occurredAt']);
    }
}
