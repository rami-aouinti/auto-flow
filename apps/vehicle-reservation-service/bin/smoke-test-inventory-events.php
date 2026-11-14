<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('RESERVATION_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('RESERVATION_DATABASE_URL is required.');
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
);
$channel = $connection->channel();
$exchange = 'autoflow.inventory.events';
$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->confirm_select();

$tenantId = 'inventory-smoke-' . bin2hex(random_bytes(6));
$unitId = Uuid::v7()->toRfc4122();
$variantId = Uuid::v7()->toRfc4122();
$vin = 'AF' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 15));

$publish = static function (string $status, int $version) use ($channel, $exchange, $tenantId, $unitId, $variantId, $vin): void {
    $eventId = Uuid::v7()->toRfc4122();
    $eventType = $version === 1 ? 'inventory.unit.created.v1' : 'inventory.status.changed.v1';
    $envelope = [
        'eventId' => $eventId,
        'eventType' => $eventType,
        'schemaVersion' => 1,
        'producer' => 'inventory-service',
        'aggregate' => ['type' => 'InventoryUnit', 'id' => $unitId],
        'tenantId' => $tenantId,
        'occurredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        'data' => [
            'inventoryUnitId' => $unitId,
            'fromStatus' => $version === 1 ? null : 'available',
            'toStatus' => $status,
            'fromLocationCode' => null,
            'toLocationCode' => null,
            'vin' => $vin,
            'vehicleVariantId' => $variantId,
            'version' => $version,
        ],
    ];
    $channel->basic_publish(new AMQPMessage(json_encode($envelope, JSON_THROW_ON_ERROR), [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'message_id' => $eventId,
        'type' => $eventType,
        'application_headers' => new AMQPTable([
            'tenantId' => $tenantId,
            'producer' => 'inventory-service',
            'schemaVersion' => 1,
        ]),
    ]), $exchange, $eventType, true);
    $channel->wait_for_pending_acks_returns(5);
};

$waitForProjection = static function (string $expectedStatus, string $expectedInventoryStatus, int $expectedVersion) use ($db, $tenantId, $unitId): array {
    $deadline = microtime(true) + 20;
    do {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, false)", ['tenant' => $tenantId]);
        $row = $db->fetchAssociative(
            'SELECT status, inventory_status, inventory_version FROM vehicle_stock_units WHERE tenant_id=:tenant AND vehicle_unit_id=:unit',
            ['tenant' => $tenantId, 'unit' => $unitId],
        );
        if ($row !== false
            && $row['status'] === $expectedStatus
            && $row['inventory_status'] === $expectedInventoryStatus
            && (int) $row['inventory_version'] === $expectedVersion
        ) {
            return $row;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException(sprintf(
        'Inventory projection did not reach %s/%s at version %d.',
        $expectedStatus,
        $expectedInventoryStatus,
        $expectedVersion,
    ));
};

try {
    $publish('available', 1);
    $waitForProjection('available', 'available', 1);
    $publish('blocked', 2);
    $waitForProjection('unavailable', 'blocked', 2);
    fwrite(STDOUT, "Inventory -> Reservation event projection smoke test passed.\n");
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
