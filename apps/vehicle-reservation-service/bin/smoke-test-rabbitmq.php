<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

require dirname(__DIR__) . '/vendor/autoload.php';

$generateUuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12),
    );
};

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
$reservationId = (string) $generateUuid();
$unitId = (string) $generateUuid();
$variantId = (string) $generateUuid();
$vin = 'AF' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 15));
$db->executeQuery(
    "SELECT set_config('app.tenant_id', :tenant, false)",
    ['tenant' => $tenant],
);

// Inventory owns physical stock. Create it through the real event contract,
// then wait until the Reservation consumer projects it before reserving it.
$inventoryExchange = 'autoflow.inventory.events';
$channel->exchange_declare($inventoryExchange, 'topic', false, true, false);
$inventoryEventId = (string) $generateUuid();
$inventoryEvent = [
    'eventId' => $inventoryEventId,
    'eventType' => 'inventory.unit.created.v1',
    'schemaVersion' => 1,
    'producer' => 'inventory-service',
    'aggregate' => ['type' => 'InventoryUnit', 'id' => $unitId],
    'tenantId' => $tenant,
    'occurredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
    'data' => [
        'inventoryUnitId' => $unitId,
        'fromStatus' => null,
        'toStatus' => 'available',
        'fromLocationCode' => null,
        'toLocationCode' => null,
        'vin' => $vin,
        'vehicleVariantId' => $variantId,
        'version' => 1,
    ],
];
$channel->confirm_select();
$channel->basic_publish(new AMQPMessage(json_encode($inventoryEvent, JSON_THROW_ON_ERROR), [
    'content_type' => 'application/json',
    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
    'message_id' => $inventoryEventId,
    'type' => 'inventory.unit.created.v1',
    'application_headers' => new AMQPTable([
        'tenantId' => $tenant,
        'producer' => 'inventory-service',
        'schemaVersion' => 1,
    ]),
]), $inventoryExchange, 'inventory.unit.created.v1', true);
$channel->wait_for_pending_acks_returns(5);

$projectionDeadline = microtime(true) + 20;
do {
    $stock = $db->fetchAssociative(
        'SELECT status, inventory_status, inventory_version FROM vehicle_stock_units WHERE tenant_id=:tenant AND vehicle_unit_id=:unit',
        ['tenant' => $tenant, 'unit' => $unitId],
    );
    if ($stock !== false
        && $stock['status'] === 'available'
        && $stock['inventory_status'] === 'available'
        && (int) $stock['inventory_version'] === 1
    ) {
        break;
    }
    usleep(250000);
} while (microtime(true) < $projectionDeadline);

if ($stock === false
    || $stock['status'] !== 'available'
    || $stock['inventory_status'] !== 'available'
    || (int) $stock['inventory_version'] !== 1
) {
    throw new RuntimeException('Inventory unit-created event was not projected as available stock.');
}

$db->insert('vehicle_reservations', [
    'tenant_id' => $tenant,
    'reservation_id' => $reservationId,
    'vehicle_unit_id' => $unitId,
    'vehicle_variant_id' => $variantId,
    'order_id' => (string) $generateUuid(),
    'status' => 'held',
    'expires_at' => (new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')))->format(DATE_ATOM),
]);

$deadline = microtime(true) + 60;
try {
    while (microtime(true) < $deadline) {
        $message = $channel->basic_get($queue, false);
        if ($message instanceof \PhpAmqpLib\Message\AMQPMessage) {
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
