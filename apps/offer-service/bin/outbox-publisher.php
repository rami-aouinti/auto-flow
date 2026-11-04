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

$connectRabbit = static function (): array {
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

[$rabbitConnection, $channel, $publisher] = $connectRabbit();
error_log('[Offer Outbox] Publisher started.');

try {
    while (true) {
        try {
            $published = $publisher->publishNext();
        } catch (Throwable $exception) {
            error_log('[Offer Outbox] Worker iteration failed: ' . $exception->getMessage());
            $published = false;
        }

        if (!$rabbitConnection->isConnected() || !$channel->is_open()) {
            try {
                if ($channel->is_open()) {
                    $channel->close();
                }
                if ($rabbitConnection->isConnected()) {
                    $rabbitConnection->close();
                }
            } catch (Throwable) {
                // The connection is already unavailable; create a fresh one below.
            }

            [$rabbitConnection, $channel, $publisher] = $connectRabbit();
        }

        if (!$published) {
            usleep(500_000);
        }
    }
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($rabbitConnection->isConnected()) {
        $rabbitConnection->close();
    }
}
