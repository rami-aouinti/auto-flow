<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Integration\Offer;

use App\Domain\ValueObject\OfferId;
use App\Infrastructure\Messaging\OutboxPublisher;
use App\Infrastructure\Persistence\PostgresConnection;
use DateTimeImmutable;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;

final class OutboxPublisherIntegrationTest extends TestCase
{
    private \PDO $database;
    private AMQPStreamConnection $rabbitConnection;

    protected function setUp(): void
    {
        $databaseUrl = getenv('OFFER_TEST_DATABASE_URL');
        $rabbitHost = getenv('RABBITMQ_TEST_HOST');

        if (!is_string($databaseUrl) || $databaseUrl === '') {
            self::markTestSkipped('Set OFFER_TEST_DATABASE_URL to run the outbox integration test.');
        }

        if (!is_string($rabbitHost) || $rabbitHost === '') {
            self::markTestSkipped('Set RABBITMQ_TEST_HOST to run the outbox integration test.');
        }

        $this->database = (new PostgresConnection($databaseUrl))->get();
        $this->rabbitConnection = new AMQPStreamConnection(
            $rabbitHost,
            (int) (getenv('RABBITMQ_TEST_PORT') ?: 5672),
            getenv('RABBITMQ_TEST_USER') ?: 'autoflow',
            getenv('RABBITMQ_TEST_PASSWORD') ?: 'autoflow',
            '/',
            false,
            'PLAIN',
            null,
            'en_US',
            3.0,
            3.0,
            null,
            true,
            30,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->rabbitConnection) && $this->rabbitConnection->isConnected()) {
            $this->rabbitConnection->close();
        }
    }

    public function testPublisherMarksEventOnlyAfterRabbitMqConfirmation(): void
    {
        $eventId = (string) OfferId::generate();
        $tenantId = 'it-broker-' . bin2hex(random_bytes(6));
        $offerId = (string) OfferId::generate();
        $occurredAt = new DateTimeImmutable();
        $envelope = [
            'eventId' => $eventId,
            'eventType' => 'OfferCreated',
            'schemaVersion' => 1,
            'occurredAt' => $occurredAt->format(DATE_ATOM),
            'producer' => 'offer-service',
            'tenantId' => $tenantId,
            'aggregate' => ['type' => 'Offer', 'id' => $offerId],
            'data' => ['offerId' => $offerId, 'quoteId' => 'quote-integration'],
        ];

        $insert = $this->database->prepare(<<<'SQL'
            INSERT INTO offer_outbox_events (
                event_id, tenant_id, event_type, aggregate_type, aggregate_id, payload, occurred_at
            ) VALUES (
                :event_id, :tenant_id, 'OfferCreated', 'Offer', :aggregate_id,
                CAST(:payload AS JSONB), :occurred_at
            )
            SQL);
        $insert->execute([
            'event_id' => $eventId,
            'tenant_id' => $tenantId,
            'aggregate_id' => $offerId,
            'payload' => json_encode($envelope, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt->format(DATE_ATOM),
        ]);

        $channel = $this->rabbitConnection->channel();
        try {
            $publisher = new OutboxPublisher(
                new PostgresConnection((string) getenv('OFFER_TEST_DATABASE_URL')),
                $channel,
            );

            self::assertTrue($publisher->publishNext());

            $published = $this->database->prepare(
                'SELECT published_at FROM offer_outbox_events WHERE event_id = :event_id',
            );
            $published->execute(['event_id' => $eventId]);

            self::assertNotNull($published->fetchColumn());
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }
}
