<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
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
$channel->exchange_declare('autoflow.vehicle-reservation.commands', 'topic', false, true, false);
$channel->exchange_declare('autoflow.vehicle-reservation.events', 'topic', false, true, false);
$channel->exchange_declare('autoflow.vehicle-reservation.commands.dlx', 'direct', false, true, false);
$channel->queue_declare('autoflow.vehicle-reservation.commands.dead', false, true, false, false);
$channel->queue_bind('autoflow.vehicle-reservation.commands.dead', 'autoflow.vehicle-reservation.commands.dlx', 'invalid');
$channel->queue_declare(
    'autoflow.vehicle-reservation.saga-commands',
    false,
    true,
    false,
    false,
    false,
    new AMQPTable([
        'x-dead-letter-exchange' => 'autoflow.vehicle-reservation.commands.dlx',
        'x-dead-letter-routing-key' => 'invalid',
    ]),
);
$channel->queue_bind('autoflow.vehicle-reservation.saga-commands', 'autoflow.vehicle-reservation.commands', '#');
$channel->basic_qos(null, 10, false);
$channel->confirm_select();

$publish = static function (array $envelope, string $routingKey) use ($channel): void {
    $body = json_encode($envelope, JSON_THROW_ON_ERROR);
    $channel->basic_publish(new AMQPMessage($body, [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'message_id' => $envelope['eventId'],
        'type' => $envelope['eventType'],
        'application_headers' => new AMQPTable(['tenantId' => $envelope['tenantId'], 'producer' => 'vehicle-reservation-service']),
    ]), 'autoflow.vehicle-reservation.events', $routingKey, true);
    $channel->wait_for_pending_acks_returns(5);
};

$channel->basic_consume(
    'autoflow.vehicle-reservation.saga-commands',
    'reservation-saga-command-consumer',
    false,
    false,
    false,
    false,
    static function (AMQPMessage $message) use ($db, $publish, $channel): void {
        try {
            $command = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($command) || array_is_list($command)) {
                throw new InvalidArgumentException('Command envelope must be a JSON object.');
            }
            $eventId = $command['eventId'] ?? null;
            $eventType = $command['eventType'] ?? null;
            $tenantId = $command['tenantId'] ?? null;
            $data = $command['data'] ?? null;
            if (!is_string($eventId) || !Uuid::isValid($eventId)
                || !is_string($tenantId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId)
                || !is_array($data) || array_is_list($data)
            ) {
                throw new InvalidArgumentException('Command requires UUID eventId, valid tenantId and object data.');
            }
            if (($message->get('message_id') ?? null) !== $eventId || ($message->get('type') ?? null) !== $eventType) {
                throw new InvalidArgumentException('RabbitMQ metadata does not match command envelope.');
            }

            $orderId = $data['orderId'] ?? null;
            if (!is_string($orderId) || !Uuid::isValid($orderId)) {
                throw new InvalidArgumentException('Command data.orderId must be a UUID.');
            }

            if ($eventType === 'reservation.hold.requested.v1') {
                $variantId = $data['vehicleVariantId'] ?? null;
                if (!is_string($variantId) || !Uuid::isValid($variantId)) {
                    throw new InvalidArgumentException('Command data.vehicleVariantId must be a UUID.');
                }
                $expiresAt = $data['expiresAt'] ?? null;
                if (!is_string($expiresAt)) {
                    throw new InvalidArgumentException('Command data.expiresAt must be an ISO-8601 timestamp.');
                }
                try {
                    $result = $db->fetchOne(
                        'SELECT public.vehicle_reservation_hold_available(:tenant, :variant, :order, :expires)::text',
                        ['tenant' => $tenantId, 'variant' => $variantId, 'order' => $orderId, 'expires' => $expiresAt],
                    );
                } catch (Throwable $exception) {
                    if (str_contains($exception->getMessage(), 'no available physical vehicle unit')) {
                        $namespace = Uuid::fromString('c7f9c52a-55f0-4a8a-9baf-cc8e4d6e0f00');
                        $rejectedId = Uuid::v5($namespace, $eventId . ':rejected')->toRfc4122();
                        $publish([
                            'eventId' => $rejectedId,
                            'eventType' => 'reservation.hold.rejected.v1',
                            'schemaVersion' => 1,
                            'producer' => 'vehicle-reservation-service',
                            'aggregate' => ['type' => 'VehicleReservationCommand', 'id' => $orderId],
                            'tenantId' => $tenantId,
                            'correlationId' => $command['correlationId'] ?? null,
                            'occurredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
                            'data' => ['orderId' => $orderId, 'vehicleVariantId' => $variantId, 'reason' => 'no_available_stock'],
                        ], 'reservation.hold.rejected.v1');
                        $channel->basic_ack($message->getDeliveryTag());
                        return;
                    }
                    throw $exception;
                }
                if (!is_string($result)) {
                    throw new RuntimeException('Stock allocation function returned no result.');
                }
                // The database Outbox publishes reservation.held.v1 atomically with the hold.
            } elseif ($eventType === 'reservation.confirm.requested.v1') {
                $reservationId = $data['reservationId'] ?? null;
                if (!is_string($reservationId) || !Uuid::isValid($reservationId)) {
                    throw new InvalidArgumentException('Command data.reservationId must be a UUID.');
                }
                $db->fetchOne(
                    'SELECT public.vehicle_reservation_confirm_for_order(:tenant, :reservation, :order)::text',
                    ['tenant' => $tenantId, 'reservation' => $reservationId, 'order' => $orderId],
                );
                // The database Outbox publishes reservation.confirmed.v1 atomically with confirmation.
            } elseif ($eventType === 'reservation.release.requested.v1') {
                $reservationId = $data['reservationId'] ?? null;
                if (!is_string($reservationId) || !Uuid::isValid($reservationId)) {
                    throw new InvalidArgumentException('Command data.reservationId must be a UUID.');
                }
                $db->fetchOne(
                    'SELECT public.vehicle_reservation_release_for_order(:tenant, :reservation, :order)::text',
                    ['tenant' => $tenantId, 'reservation' => $reservationId, 'order' => $orderId],
                );
                // The database Outbox publishes reservation.released.v1 atomically with compensation.
            } else {
                throw new InvalidArgumentException(sprintf('Unsupported reservation command %s.', (string) $eventType));
            }

            $channel->basic_ack($message->getDeliveryTag());
        } catch (InvalidArgumentException|JsonException $exception) {
            fwrite(STDERR, sprintf("Rejecting invalid reservation Saga command: %s\n", $exception->getMessage()));
            $channel->basic_nack($message->getDeliveryTag(), false, false);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("Reservation Saga command failed; requeueing: %s\n", $exception->getMessage()));
            $channel->basic_nack($message->getDeliveryTag(), false, true);
        }
    },
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
