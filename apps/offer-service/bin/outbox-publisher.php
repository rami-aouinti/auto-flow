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

$connectRabbit = static function () use ($databaseUrl): array {
    $connection = new AMQPStreamConnection(
        getenv('RABBITMQ_HOST') ?: 'rabbitmq',
        (int) (getenv('RABBITMQ_PORT') ?: 5672),
        getenv('RABBITMQ_USER') ?: 'autoflow',
        getenv('RABBITMQ_PASSWORD') ?: 'autoflow',
        getenv('RABBITMQ_VHOST') ?: '/',
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
    $channel = $connection->channel();

    return [
        $connection,
        $channel,
        new OutboxPublisher(new PostgresConnection($databaseUrl), $channel),
    ];
};

$closeRabbit = static function (
    ?AMQPStreamConnection &$connection,
    &$channel,
): void {
    try {
        if ($channel !== null && $channel->is_open()) {
            $channel->close();
        }
    } catch (Throwable) {
        // The channel may already be closed by the broker.
    }

    try {
        if ($connection !== null && $connection->isConnected()) {
            $connection->close();
        }
    } catch (Throwable) {
        // The connection may already be unavailable.
    }

    $channel = null;
    $connection = null;
};

$rabbitConnection = null;
$channel = null;
$publisher = null;

error_log('[Offer Outbox] Publisher started.');

while (true) {
    try {
        if (
            !($rabbitConnection instanceof AMQPStreamConnection)
            || !$rabbitConnection->isConnected()
            || $channel === null
            || !$channel->is_open()
        ) {
            $closeRabbit($rabbitConnection, $channel);
            [$rabbitConnection, $channel, $publisher] = $connectRabbit();
            error_log('[Offer Outbox] RabbitMQ connection established.');
        }

        // Service heartbeats while the publisher is idle.
        $rabbitConnection->checkHeartBeat();

        $published = $publisher->publishNext();

        // A publisher confirm may block; service heartbeats again afterwards.
        $rabbitConnection->checkHeartBeat();

        if (!$published) {
            usleep(500_000);
        }
    } catch (Throwable $exception) {
        error_log('[Offer Outbox] Worker iteration failed; reconnecting safely: ' . $exception->getMessage());
        $closeRabbit($rabbitConnection, $channel);
        $publisher = null;
        usleep(1_000_000);
    }
}
