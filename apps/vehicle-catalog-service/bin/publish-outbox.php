<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\PostgresConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    throw new RuntimeException('DATABASE_URL is required.');
}
$db = (new PostgresConnection($url))->get();
$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST') ?: 'rabbitmq',
    (int) (getenv('RABBITMQ_PORT') ?: 5672),
    getenv('RABBITMQ_USER') ?: 'autoflow',
    getenv('RABBITMQ_PASSWORD') ?: 'autoflow',
    getenv('RABBITMQ_VHOST') ?: '/',
);
$channel = $connection->channel();
$channel->exchange_declare('autoflow.catalog.events', 'topic', false, true, false);
$channel->confirm_select();

try {
    while (true) {
        $db->beginTransaction();
        $claim = $db->query(<<<'SQL'
            WITH candidate AS (
                SELECT event_id FROM catalog_outbox
                WHERE published_at IS NULL
                  AND next_attempt_at <= CURRENT_TIMESTAMP
                  AND (locked_until IS NULL OR locked_until < CURRENT_TIMESTAMP)
                ORDER BY occurred_at
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            )
            UPDATE catalog_outbox AS outbox
            SET locked_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds',
                attempts = attempts + 1
            FROM candidate
            WHERE outbox.event_id = candidate.event_id
            RETURNING outbox.event_id, outbox.event_type, outbox.payload, outbox.attempts
            SQL);
        $event = $claim->fetch();
        $db->commit();

        if (!is_array($event)) {
            usleep(500000);
            continue;
        }

        try {
            $message = new AMQPMessage((string) $event['payload'], [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => trim((string) $event['event_id']),
                'type' => (string) $event['event_type'],
            ]);
            $channel->basic_publish($message, 'autoflow.catalog.events', (string) $event['event_type'], true);
            $channel->wait_for_pending_acks_returns(5);
            $done = $db->prepare(
                'UPDATE catalog_outbox '
                . 'SET published_at = CURRENT_TIMESTAMP, locked_until = NULL, last_error = NULL '
                . 'WHERE event_id = :id',
            );
            $done->execute(['id' => $event['event_id']]);
        } catch (Throwable $error) {
            $retry = $db->prepare(<<<'SQL'
                UPDATE catalog_outbox
                SET locked_until=NULL,
                    next_attempt_at = CURRENT_TIMESTAMP + make_interval(
                        secs => LEAST(300, POWER(2, LEAST(attempts, 8))::integer)
                    ),
                    last_error=:error
                WHERE event_id=:id
                SQL);
            $retry->execute(['id' => $event['event_id'], 'error' => mb_substr($error->getMessage(), 0, 500)]);
            throw $error;
        }
    }
} finally {
    $channel->close();
    $connection->close();
}