<?php

declare(strict_types=1);

use App\Infrastructure\Messaging\OutboxPublisher;
use App\Infrastructure\Persistence\PostgresConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('OFFER_DATABASE_URL');
if (!is_string($databaseUrl) || $databaseUrl === '') {
    throw new RuntimeException('OFFER_DATABASE_URL is required.');
}

$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST') ?: 'rabbitmq',
    (int) (getenv('RABBITMQ_PORT') ?: 5672),
    getenv('RABBITMQ_USER') ?: 'autoflow',
    getenv('RABBITMQ_PASSWORD') ?: 'autoflow',
    getenv('RABBITMQ_VHOST') ?: '/',
    false,
    'AMQPLAIN',
    null,
    'en_US',
    3.0,
    3.0,
    null,
    true,
    30,
);

$channel = $connection->channel();
$publisher = new OutboxPublisher(new PostgresConnection($databaseUrl), $channel);

error_log('[Offer Outbox] Publisher started.');

try {
    while (true) {
        try {
            if (!$publisher->publishNext()) {
                usleep(500_000);
            }
        } catch (Throwable $exception) {
            error_log('[Offer Outbox] Worker iteration failed: ' . $exception->getMessage());
            sleep(2);
        }
    }
} finally {
    $channel->close();
    $connection->close();
}
