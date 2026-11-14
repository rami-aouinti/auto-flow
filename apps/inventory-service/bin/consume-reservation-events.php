<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('INVENTORY_RESERVATION_WORKER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('INVENTORY_RESERVATION_WORKER_DATABASE_URL is required.');
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
$queue = 'autoflow.inventory.reservation-events';
$deadExchange = 'autoflow.inventory.reservation-events.dlx';
$deadQueue = 'autoflow.inventory.reservation-events.dead';

$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->exchange_declare($deadExchange, 'direct', false, true, false);
$channel->queue_declare($deadQueue, false, true, false, false);
$channel->queue_bind($deadQueue, $deadExchange, 'invalid');
$channel->queue_declare($queue, false, true, false, false, false, new PhpAmqpLib\Wire\AMQPTable([
    'x-dead-letter-exchange' => $deadExchange,
    'x-dead-letter-routing-key' => 'invalid',
]));
$channel->queue_bind($queue, $exchange, 'reservation.fulfilled.v1');
$channel->basic_qos(null, 20, false);

fwrite(STDOUT, "Inventory Reservation fulfillment consumer started.\n");
$channel->basic_consume($queue, 'inventory-reservation-fulfillment', false, false, false, false,
    static function (AMQPMessage $message) use ($db, $channel): void {
        try {
            $event = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($event) || array_is_list($event)) {
                throw new InvalidArgumentException('Reservation event must be a JSON object.');
            }
            $eventId = $event['eventId'] ?? null;
            $eventType = $event['eventType'] ?? null;
            $tenantId = $event['tenantId'] ?? null;
            $data = $event['data'] ?? null;
            if (!is_string($eventId) || !Uuid::isValid($eventId)
                || !is_string($tenantId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)
                || !is_array($data) || array_is_list($data)
                || $eventType !== 'reservation.fulfilled.v1'
                || ($event['producer'] ?? null) !== 'vehicle-reservation-service'
            ) {
                throw new InvalidArgumentException('Reservation fulfillment event envelope is invalid.');
            }
            if (($message->get('message_id') ?? null) !== $eventId
                || ($message->get('type') ?? null) !== $eventType
            ) {
                throw new InvalidArgumentException('RabbitMQ metadata does not match Reservation event envelope.');
            }

            $reservationId = $data['id'] ?? $data['reservationId'] ?? null;
            $unitId = $data['vehicleUnitId'] ?? null;
            $variantId = $data['vehicleVariantId'] ?? null;
            $orderId = $data['orderId'] ?? null;
            $status = $data['status'] ?? null;
            $version = $data['version'] ?? null;
            if (!is_string($reservationId) || !Uuid::isValid($reservationId)
                || !is_string($unitId) || !Uuid::isValid($unitId)
                || !is_string($variantId) || !Uuid::isValid($variantId)
                || !is_string($orderId) || !Uuid::isValid($orderId)
                || $status !== 'fulfilled'
                || !is_int($version) || $version < 1
            ) {
                throw new InvalidArgumentException('Reservation fulfillment data is missing a valid reservation, unit, variant, order, status or version.');
            }

            $db->fetchOne(
                'SELECT public.inventory_apply_reservation_fulfilled(CAST(:tenant AS VARCHAR(128)),CAST(:event AS UUID),CAST(:reservation AS UUID),CAST(:unit AS UUID),CAST(:variant AS UUID),CAST(:order AS UUID),CAST(:version AS INTEGER))',
                [
                    'tenant' => $tenantId,
                    'event' => $eventId,
                    'reservation' => $reservationId,
                    'unit' => $unitId,
                    'variant' => $variantId,
                    'order' => $orderId,
                    'version' => $version,
                ],
            );
            $channel->basic_ack($message->getDeliveryTag());
        } catch (InvalidArgumentException|JsonException $exception) {
            fwrite(STDERR, 'Rejecting invalid Reservation fulfillment event: ' . $exception->getMessage() . "\n");
            $channel->basic_nack($message->getDeliveryTag(), false, false);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Reservation fulfillment failed; requeueing: ' . $exception->getMessage() . "\n");
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
