<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
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
);
$channel = $connection->channel();
$exchange = 'autoflow.vehicle-reservation.events';
$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->confirm_select();

$tenantId = 'reservation-smoke-' . bin2hex(random_bytes(6));
$unitId = Uuid::v7()->toRfc4122();
$variantId = Uuid::v7()->toRfc4122();
$reservationId = Uuid::v7()->toRfc4122();
$orderId = Uuid::v7()->toRfc4122();
$eventId = Uuid::v7()->toRfc4122();
$vin = 'AF' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 15));

try {
    $db->transactional(static function ($db) use ($tenantId, $unitId, $variantId, $vin): void {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);
        $db->insert('inventory_units', [
            'tenant_id' => $tenantId,
            'inventory_unit_id' => $unitId,
            'vehicle_variant_id' => $variantId,
            'vin' => $vin,
            'vin_normalized' => $vin,
            'status' => 'available',
            'condition' => 'new',
            'created_by' => 'inventory-fulfillment-smoke-test',
            'updated_by' => 'inventory-fulfillment-smoke-test',
        ]);
    });

    $event = [
        'eventId' => $eventId,
        'eventType' => 'reservation.fulfilled.v1',
        'schemaVersion' => 1,
        'producer' => 'vehicle-reservation-service',
        'aggregate' => ['type' => 'VehicleReservation', 'id' => $reservationId],
        'tenantId' => $tenantId,
        'occurredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        'data' => [
            'id' => $reservationId,
            'vehicleUnitId' => $unitId,
            'vehicleVariantId' => $variantId,
            'orderId' => $orderId,
            'status' => 'fulfilled',
            'version' => 3,
        ],
    ];
    $message = new AMQPMessage(json_encode($event, JSON_THROW_ON_ERROR), [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'message_id' => $eventId,
        'type' => 'reservation.fulfilled.v1',
        'application_headers' => new AMQPTable([
            'tenantId' => $tenantId,
            'producer' => 'vehicle-reservation-service',
            'schemaVersion' => 1,
        ]),
    ]);

    // A duplicate delivery must not create a second Inventory transition.
    $channel->basic_publish($message, $exchange, 'reservation.fulfilled.v1', true);
    $channel->wait_for_pending_acks_returns(5);
    $channel->basic_publish($message, $exchange, 'reservation.fulfilled.v1', true);
    $channel->wait_for_pending_acks_returns(5);

    $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, false)", ['tenant' => $tenantId]);
    $deadline = microtime(true) + 20;
    do {
        $row = $db->fetchAssociative(
            'SELECT status, version FROM inventory_units WHERE tenant_id=:tenant AND inventory_unit_id=:unit',
            ['tenant' => $tenantId, 'unit' => $unitId],
        );
        if ($row !== false && $row['status'] === 'sold' && (int) $row['version'] === 2) {
            break;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);

    if ($row === false || $row['status'] !== 'sold' || (int) $row['version'] !== 2) {
        throw new RuntimeException('Inventory did not transition to sold after reservation fulfillment.');
    }

    $inboxCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM inventory_reservation_inbox WHERE tenant_id=:tenant AND event_id=:event',
        ['tenant' => $tenantId, 'event' => $eventId],
    );
    $outboxCount = (int) $db->fetchOne(
        "SELECT COUNT(*) FROM inventory_outbox_events WHERE tenant_id=:tenant AND inventory_unit_id=:unit AND event_type='inventory.status.changed.v1'",
        ['tenant' => $tenantId, 'unit' => $unitId],
    );
    $movementCount = (int) $db->fetchOne(
        "SELECT COUNT(*) FROM inventory_movements WHERE tenant_id=:tenant AND inventory_unit_id=:unit AND from_status='available' AND to_status='sold'",
        ['tenant' => $tenantId, 'unit' => $unitId],
    );

    if ($inboxCount !== 1 || $outboxCount !== 1 || $movementCount !== 1) {
        throw new RuntimeException('Fulfillment inbox, Inventory Outbox or movement was duplicated.');
    }

    fwrite(STDOUT, "Reservation -> Inventory fulfillment and duplicate-delivery smoke test passed.\n");
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
