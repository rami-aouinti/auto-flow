<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('INVENTORY_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('INVENTORY_DATABASE_URL is required.');
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
$exchange = 'autoflow.inventory.events';
$routingKey = 'inventory.unit.created.v1';
$channel->exchange_declare($exchange, 'topic', false, true, false);
[$queue] = $channel->queue_declare('', false, false, true, true);
$channel->queue_bind($queue, $exchange, $routingKey);

$tenant = 'inventory-smoke-' . bin2hex(random_bytes(6));
$unitId = Uuid::v7()->toRfc4122();
$eventId = Uuid::v7()->toRfc4122();
$variantId = Uuid::v7()->toRfc4122();
$vin = 'WVWZZZ1JZXW' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$occurredAt = (new DateTimeImmutable())->format(DATE_ATOM);
$payload = [
    'eventId' => $eventId,
    'eventType' => $routingKey,
    'schemaVersion' => 1,
    'producer' => 'inventory-service',
    'aggregate' => ['type' => 'InventoryUnit', 'id' => $unitId],
    'tenantId' => $tenant,
    'occurredAt' => $occurredAt,
    'data' => [
        'inventoryUnitId' => $unitId,
        'vehicleVariantId' => $variantId,
        'vin' => $vin,
        'fromStatus' => null,
        'toStatus' => 'available',
    ],
];

$db->transactional(static function ($db) use ($tenant, $unitId, $variantId, $vin, $eventId, $payload): void {
    $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
    $db->insert('inventory_units', [
        'tenant_id' => $tenant,
        'inventory_unit_id' => $unitId,
        'vehicle_variant_id' => $variantId,
        'vin' => $vin,
        'vin_normalized' => $vin,
        'status' => 'available',
        'condition' => 'new',
        'created_by' => 'inventory-smoke-test',
        'updated_by' => 'inventory-smoke-test',
    ]);
    $db->insert('inventory_outbox_events', [
        'tenant_id' => $tenant,
        'event_id' => $eventId,
        'inventory_unit_id' => $unitId,
        'event_type' => 'inventory.unit.created.v1',
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);
});

$deadline = microtime(true) + 60;

try {
    while (microtime(true) < $deadline) {
        $message = $channel->basic_get($queue, false);
        if ($message instanceof AMQPMessage) {
            $received = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (($received['eventId'] ?? null) !== $eventId
                || ($received['tenantId'] ?? null) !== $tenant
                || ($received['eventType'] ?? null) !== $routingKey) {
                $message->nack(false, false);
                throw new RuntimeException('RabbitMQ delivered an unexpected inventory event.');
            }

            $message->ack();
            fwrite(STDOUT, sprintf("Inventory Outbox end-to-end OK: %s (%s).%s", $routingKey, $eventId, PHP_EOL));
            exit(0);
        }

        usleep(250000);
    }

    throw new RuntimeException('Timed out waiting for Inventory Outbox publication.');
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
