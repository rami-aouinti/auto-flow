<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('RESERVATION_WORKER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('RESERVATION_WORKER_DATABASE_URL is required.');
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
$queue = 'autoflow.vehicle-reservation.inventory-events';
$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->exchange_declare('autoflow.vehicle-reservation.inventory-events.dlx', 'direct', false, true, false);
$channel->queue_declare('autoflow.vehicle-reservation.inventory-events.dead', false, true, false, false);
$channel->queue_bind('autoflow.vehicle-reservation.inventory-events.dead', 'autoflow.vehicle-reservation.inventory-events.dlx', 'invalid');
$channel->queue_declare($queue, false, true, false, false, false, new PhpAmqpLib\Wire\AMQPTable([
    'x-dead-letter-exchange' => 'autoflow.vehicle-reservation.inventory-events.dlx',
    'x-dead-letter-routing-key' => 'invalid',
]));
$channel->queue_bind($queue, $exchange, 'inventory.unit.created.v1');
$channel->queue_bind($queue, $exchange, 'inventory.status.changed.v1');
$channel->basic_qos(null, 20, false);

fwrite(STDOUT, "Reservation Inventory event consumer started.\n");
$channel->basic_consume($queue, 'reservation-inventory-projection', false, false, false, false,
    static function (AMQPMessage $message) use ($db, $channel): void {
        try {
            $event = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($event) || array_is_list($event)) {
                throw new InvalidArgumentException('Inventory event must be a JSON object.');
            }
            $eventId = $event['eventId'] ?? null;
            $eventType = $event['eventType'] ?? null;
            $tenantId = $event['tenantId'] ?? null;
            $data = $event['data'] ?? null;
            if (!is_string($eventId) || !Uuid::isValid($eventId)
                || !is_string($tenantId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)
                || !is_array($data) || array_is_list($data)
                || !in_array($eventType, ['inventory.unit.created.v1','inventory.status.changed.v1'], true)
            ) {
                throw new InvalidArgumentException('Inventory event envelope is invalid.');
            }
            if (($message->get('message_id') ?? null) !== $eventId
                || ($message->get('type') ?? null) !== $eventType
            ) {
                throw new InvalidArgumentException('RabbitMQ metadata does not match Inventory event envelope.');
            }
            $unitId = $data['inventoryUnitId'] ?? ($event['aggregate']['id'] ?? null);
            $variantId = $data['vehicleVariantId'] ?? null;
            $vin = $data['vin'] ?? null;
            $status = $data['toStatus'] ?? null;
            $version = $data['version'] ?? null;
            if (!is_string($unitId) || !Uuid::isValid($unitId)
                || !is_string($variantId) || !Uuid::isValid($variantId)
                || !is_string($vin) || !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', strtoupper($vin))
                || !is_string($status)
                || !is_int($version) || $version < 1
            ) {
                throw new InvalidArgumentException('Inventory event data is missing unit, variant, VIN, status or version.');
            }
            $db->fetchOne(
                'SELECT public.vehicle_stock_apply_inventory_event(CAST(:tenant AS VARCHAR(128)),CAST(:event AS UUID),CAST(:unit AS UUID),CAST(:variant AS UUID),CAST(:vin AS VARCHAR(17)),CAST(:status AS VARCHAR(24)),CAST(:version AS INTEGER))',
                [
                    'tenant' => $tenantId,
                    'event' => $eventId,
                    'unit' => $unitId,
                    'variant' => $variantId,
                    'vin' => strtoupper($vin),
                    'status' => $status,
                    'version' => $version,
                ],
            );
            $channel->basic_ack($message->getDeliveryTag());
        } catch (InvalidArgumentException|JsonException $exception) {
            fwrite(STDERR, 'Rejecting invalid Inventory event: ' . $exception->getMessage() . "\n");
            $channel->basic_nack($message->getDeliveryTag(), false, false);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Inventory projection failed; requeueing: ' . $exception->getMessage() . "\n");
            $channel->basic_nack($message->getDeliveryTag(), false, true);
        }
    }
);

try {
    while ($channel->is_consuming()) {
        $channel->wait();
    }
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
