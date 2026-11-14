<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('FULFILLMENT_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('FULFILLMENT_DATABASE_URL is required.');
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
$exchanges = [
    IntegrationEvent::PAYMENT_CAPTURED => 'autoflow.payment.events',
    IntegrationEvent::CONTRACT_SIGNED => 'autoflow.contract.events',
    IntegrationEvent::RESERVATION_CONFIRMED => 'autoflow.vehicle-reservation.events',
];
foreach (array_unique(array_values($exchanges)) as $exchange) {
    $channel->exchange_declare($exchange, 'topic', false, true, false);
}
$deadQueue = 'autoflow.fulfillment.integration-events.dlq';
$channel->queue_declare($deadQueue, false, true, false, false);
$channel->confirm_select();

$tenant = 'fulfillment-e2e-' . bin2hex(random_bytes(6));
$orderId = Uuid::v7()->toRfc4122();
$occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);

$publish = static function (string $eventType, string $producer, string $eventId, string $occurredAt) use ($channel, $exchanges, $tenant, $orderId): void {
    $envelope = [
        'eventId' => $eventId,
        'eventType' => $eventType,
        'schemaVersion' => 1,
        'producer' => $producer,
        'aggregate' => ['type' => 'Order', 'id' => $orderId],
        'tenantId' => $tenant,
        'occurredAt' => $occurredAt,
        'data' => ['orderId' => $orderId],
    ];
    $channel->basic_publish(new AMQPMessage(json_encode($envelope, JSON_THROW_ON_ERROR), [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'message_id' => $eventId,
        'type' => $eventType,
    ]), $exchanges[$eventType], $eventType, true);
    $channel->wait_for_pending_acks_returns(5);
};

$db->executeQuery("SELECT set_config('app.tenant_id', :tenant, false)", ['tenant' => $tenant]);
$waitFor = static function (callable $query, string $description) use ($db): array {
    $deadline = microtime(true) + 25;
    do {
        $row = $query();
        if (is_array($row)) {
            return $row;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for ' . $description . '.');
};

try {
    $paymentEventId = Uuid::v7()->toRfc4122();
    $contractEventId = Uuid::v7()->toRfc4122();
    $reservationEventId = Uuid::v7()->toRfc4122();

    $publish(IntegrationEvent::PAYMENT_CAPTURED, 'payment-service', $paymentEventId, $occurredAt);
    $publish(IntegrationEvent::CONTRACT_SIGNED, 'contract-service', $contractEventId, $occurredAt);

    $partial = $waitFor(
        static fn (): array|false => $db->fetchAssociative(
            'SELECT status,version,payment_captured_at,contract_signed_at,reservation_confirmed_at
             FROM fulfillment_records WHERE tenant_id=:tenant AND order_id=:order',
            ['tenant' => $tenant, 'order' => $orderId],
        ),
        'the first two Fulfillment prerequisites',
    );
    if ($partial['status'] !== 'awaiting_prerequisites'
        || $partial['payment_captured_at'] === null
        || $partial['contract_signed_at'] === null
        || $partial['reservation_confirmed_at'] !== null
    ) {
        throw new RuntimeException('Fulfillment advanced before all three prerequisites were observed.');
    }
    $versionBeforeFinal = (int) $partial['version'];

    $publish(IntegrationEvent::RESERVATION_CONFIRMED, 'vehicle-reservation-service', $reservationEventId, $occurredAt);
    $ready = $waitFor(
        static fn (): array|false => $db->fetchAssociative(
            "SELECT status,version FROM fulfillment_records WHERE tenant_id=:tenant AND order_id=:order AND status='ready_for_preparation'",
            ['tenant' => $tenant, 'order' => $orderId],
        ),
        'all three prerequisites and ready_for_preparation state',
    );
    $versionAfterReady = (int) $ready['version'];
    if ($versionAfterReady <= $versionBeforeFinal) {
        throw new RuntimeException('Fulfillment version did not advance after the final prerequisite.');
    }

    // Exact replay is idempotent; a changed payload under the same eventId is dead-lettered.
    $publish(IntegrationEvent::RESERVATION_CONFIRMED, 'vehicle-reservation-service', $reservationEventId, $occurredAt);
    $collisionTime = (new DateTimeImmutable('+1 second', new DateTimeZone('UTC')))->format(DATE_ATOM);
    $publish(IntegrationEvent::RESERVATION_CONFIRMED, 'vehicle-reservation-service', $reservationEventId, $collisionTime);

    $deadline = microtime(true) + 20;
    $deadLetter = null;
    do {
        $deadLetter = $channel->basic_get($deadQueue, false);
        if ($deadLetter instanceof AMQPMessage) {
            break;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);
    if (!$deadLetter instanceof AMQPMessage) {
        throw new RuntimeException('Conflicting prerequisite event was not dead-lettered.');
    }
    $deadLetter->ack();

    $final = $db->fetchAssociative(
        'SELECT status,version FROM fulfillment_records WHERE tenant_id=:tenant AND order_id=:order',
        ['tenant' => $tenant, 'order' => $orderId],
    );
    $inboxCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM fulfillment_event_inbox WHERE tenant_id=:tenant',
        ['tenant' => $tenant],
    );
    $auditCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM fulfillment_audit_events WHERE tenant_id=:tenant',
        ['tenant' => $tenant],
    );
    $outboxCount = (int) $db->fetchOne(
        "SELECT COUNT(*) FROM fulfillment_outbox WHERE tenant_id=:tenant AND event_type='fulfillment.ready_for_preparation.v1'",
        ['tenant' => $tenant],
    );
    if (!is_array($final) || $final['status'] !== 'ready_for_preparation'
        || (int) $final['version'] !== $versionAfterReady
        || $inboxCount !== 3 || $auditCount !== 3 || $outboxCount !== 1
    ) {
        throw new RuntimeException('Fulfillment prerequisite, Inbox, audit or Outbox idempotency invariants failed.');
    }

    fwrite(STDOUT, "Payment + Contract + Reservation -> Fulfillment prerequisite integration test passed.\n");
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
