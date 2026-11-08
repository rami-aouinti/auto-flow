<?php

declare(strict_types=1);

use Doctrine\\DBAL\\DriverManager;
use PhpAmqpLib\\Connection\\AMQPStreamConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('RESERVATION_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('RESERVATION_DATABASE_URL is required.');
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
$exchange = 'autoflow.vehicle-reservation.events';
$routingKey = 'reservation.expired.v1';
$channel->exchange_declare($exchange, 'topic', false, true, false);
[$queue] = $channel->queue_declare('', false, false, true, true);
$channel->queue_bind($queue, $exchange, $routingKey);

$tenant = 'reservation-smoke-' . bin2hex(random_bytes(6));
$reservationId = (string) \\Symfony\\Component\\Uid\\Uuid::v7();
$statement = $db->prepare(
    "SELECT set_config('app.tenant_id', :tenant, false)"
);
$statement->executeStatement(['tenant' => $tenant]);

$db->insert('vehicle_reservations', [
    'tenant_id' => $tenant,
    'reservation_id' => $reservationId,
    'vehicle_unit_id' => (string) \\Symfony\\Component\\Uid\\Uuid::v7(),
    'vehicle_variant_id' => (string) \\Symfony\\Component\\Uid\\Uuid::v7(),
    'order_id' => (string) \\Symfony\\Component\\Uid\\Uuid::v7(),
    'status' => 'held',
    'expires_at' => (new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')))->format(DATE_ATOM),
]);

$deadline = microtime(true) + 60;
try {
    while (microtime(true) < $deadline) {
        $message = $channel->basic_get($queue, false);
        if ($message !== null) {
            $payload = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (($payload['eventType'] ?? null) !== $routingKey
                || ($payload['tenantId'] ?? null) !== $tenant
                || ($payload['data']['reservationId'] ?? null) !== $reservationId) {
                $message->nack(false, false);
                throw new RuntimeException('RabbitMQ delivered an unexpected reservation event.');
            }

            $message->ack();
            fwrite(STDOUT, sprintf("RabbitMQ end-to-end OK: %s (%s).%s", $routingKey, $reservationId, PHP_EOL));
            exit(0);
        }

        usleep(250000);
    }

    throw new RuntimeException('Timed out waiting for the expiry worker and Outbox publisher.');
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
