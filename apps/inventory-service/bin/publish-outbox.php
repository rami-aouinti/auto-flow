<?php

declare(strict_types=1);

use Doctrine\\DBAL\\DriverManager;
use PhpAmqpLib\\Connection\\AMQPStreamConnection;
use PhpAmqpLib\\Message\\AMQPMessage;
use PhpAmqpLib\\Wire\\AMQPTable;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('INVENTORY_PUBLISHER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('INVENTORY_PUBLISHER_DATABASE_URL is required.');
}

$parts = parse_url($databaseUrl);
if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
    throw new RuntimeException('A valid PostgreSQL database URL is required.');
}

$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'host' => $parts['host'] ?? 'localhost',
    'port' => $parts['port'] ?? 5432,
    'user' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
    'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
    'dbname' => rawurldecode(ltrim($parts['path'] ?? '', '/')),
]);

$exchange = 'autoflow.inventory.events';
$queue = 'autoflow.inventory.events';

$connect = static function () use ($exchange, $queue): array {
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
    $channel->exchange_declare($exchange, 'topic', false, true, false);
    $channel->queue_declare($queue, false, true, false, false);
    $channel->queue_bind($queue, $exchange, '#');
    $channel->confirm_select();

    return [$connection, $channel];
};

[$connection, $channel] = $connect();
fwrite(STDOUT, "Inventory Outbox Publisher started.\\n");

try {
    while (true) {
        try {
            $event = $db->fetchAssociative('SELECT * FROM public.inventory_outbox_claim()');
        } catch (Throwable $error) {
            fwrite(STDERR, 'Outbox claim failed: ' . $error->getMessage() . "\\n");
            sleep(2);
            continue;
        }

        if (!is_array($event)) {
            try {
                $connection->checkHeartBeat();
            } catch (Throwable) {
                try {
                    if ($channel->is_open()) {
                        $channel->close();
                    }
                } catch (Throwable) {
                }
                try {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                } catch (Throwable) {
                }
                [$connection, $channel] = $connect();
            }
            usleep(500000);
            continue;
        }

        $tenantId = (string) $event['tenant_id'];
        $eventId = (string) $event['event_id'];
        $eventType = (string) $event['event_type'];
        $payload = is_string($event['payload'])
            ? $event['payload']
            : json_encode($event['payload'], JSON_THROW_ON_ERROR);

        try {
            $returned = false;
            $channel->set_return_listener(
                static function () use (&$returned): void {
                    $returned = true;
                }
            );

            $message = new AMQPMessage($payload, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'type' => $eventType,
                'timestamp' => time(),
                'application_headers' => new AMQPTable([
                    'tenantId' => $tenantId,
                    'producer' => 'inventory-service',
                    'schemaVersion' => 1,
                ]),
            ]);

            $channel->basic_publish($message, $exchange, $eventType, true);
            $channel->wait_for_pending_acks_returns(5);

            if ($returned) {
                throw new RuntimeException('RabbitMQ returned the mandatory inventory event.');
            }

            $marked = $db->fetchOne(
                'SELECT public.inventory_outbox_mark_published(:tenant, :id)',
                ['tenant' => $tenantId, 'id' => $eventId],
            );

            if (!$marked) {
                throw new RuntimeException('Outbox event could not be marked as published.');
            }
        } catch (Throwable $error) {
            try {
                $db->executeStatement(
                    'SELECT public.inventory_outbox_schedule_retry(:tenant, :id, :error)',
                    [
                        'tenant' => $tenantId,
                        'id' => $eventId,
                        'error' => mb_substr($error->getMessage(), 0, 500),
                    ],
                );
            } catch (Throwable $databaseError) {
                fwrite(STDERR, 'Could not schedule Outbox retry: ' . $databaseError->getMessage() . "\\n");
            }

            fwrite(STDERR, sprintf(
                "Inventory event %s publication failed (attempt %d): %s\\n",
                $eventId,
                (int) $event['attempts'],
                $error->getMessage(),
            ));

            try {
                $connection->checkHeartBeat();
            } catch (Throwable) {
                try {
                    if ($channel->is_open()) {
                        $channel->close();
                    }
                } catch (Throwable) {
                }
                try {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                } catch (Throwable) {
                }
                [$connection, $channel] = $connect();
            }
        }
    }
} finally {
    try {
        if ($channel->is_open()) {
            $channel->close();
        }
    } finally {
        if ($connection->isConnected()) {
            $connection->close();
        }
    }
}
