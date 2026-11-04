<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Messaging\IntegrationEvent;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class IntegrationEventTest extends TestCase
{
    public function testFactoryCreatesVersionedSerializableEventMetadata(): void
    {
        $event = IntegrationEvent::create(
            eventName: 'pricing.quote.calculated',
            aggregateId: 'quote-123',
            payload: ['currency' => 'EUR'],
            correlationId: 'request-123',
            causationId: 'command-456',
        );

        $serialized = $event->jsonSerialize();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $event->eventId,
        );
        self::assertSame('pricing.quote.calculated', $serialized['eventName']);
        self::assertSame('quote-123', $serialized['aggregateId']);
        self::assertSame(1, $serialized['schemaVersion']);
        self::assertSame('request-123', $serialized['correlationId']);
        self::assertSame('command-456', $serialized['causationId']);
        self::assertSame(['currency' => 'EUR'], $serialized['payload']);
        self::assertIsString($serialized['occurredAt']);
    }
}
