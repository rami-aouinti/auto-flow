<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('RESERVATION_PUBLISHER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('RESERVATION_PUBLISHER_DATABASE_URL is required.');
}

/** @var array{scheme?:string,host?:string,port?:int,user?:string,pass?:string,path?:string}|false $parts */
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

$connect = static function (): array {
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
    $channel->exchange_declare('autoflow.vehicle-reservation.events', 'topic', false, true, false);
    $channel->queue_declare('autoflow.vehicle-reservation.events', false, true, false, false);
    $channel->queue_bind('autoflow.vehicle-reservation.events', 'autoflow.vehicle-reservation.events', '#');
    $channel->confirm_select();

    return [$connection, $channel];
};

[$connection, $channel] = $connect();

try {
    while (true) {
        $event = $db->fetchAssociative('SELECT * FROM public.vehicle_reservation_outbox_claim()');

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
            $message = new AMQPMessage($payload, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'type' => $eventType,
                'timestamp' => time(),
                'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                    'tenantId' => $tenantId,
                    'producer' => 'vehicle-reservation-service',
                ]),
            ]);
            $channel->basic_publish($message, 'autoflow.vehicle-reservation.events', $eventType, true);
            $channel->wait_for_pending_acks_returns(5);
            $db->executeQuery(
                'SELECT public.vehicle_reservation_outbox_mark_published(:tenant, :id)',
                ['tenant' => $tenantId, 'id' => $eventId],
            );
        } catch (Throwable $error) {
            $db->executeQuery(
                'SELECT public.vehicle_reservation_outbox_schedule_retry(:tenant, :id, :error)',
                [
                    'tenant' => $tenantId,
                    'id' => $eventId,
                    'error' => mb_substr($error->getMessage(), 0, 500),
                ],
            );
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
