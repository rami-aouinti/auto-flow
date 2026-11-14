<?php

declare(strict_types=1);

use App\Application\PaymentEventEnvelope;
use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('ORDER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('ORDER_DATABASE_URL is required.');
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
$exchange = 'autoflow.payment.events';
$queue = 'autoflow.order.payment-events';
$deadExchange = 'autoflow.order.payment-events.dlx';
$deadQueue = 'autoflow.order.payment-events.dead';

$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->exchange_declare($deadExchange, 'direct', false, true, false);
$channel->queue_declare($deadQueue, false, true, false, false);
$channel->queue_bind($deadQueue, $deadExchange, 'invalid');
$channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
    'x-dead-letter-exchange' => $deadExchange,
    'x-dead-letter-routing-key' => 'invalid',
]));
foreach (['PaymentAuthorized', 'PaymentCaptured', 'PaymentFailed', 'PaymentRefunded', 'PaymentPartiallyRefunded', 'PaymentCancelled'] as $eventType) {
    $channel->queue_bind($queue, $exchange, $eventType);
}
$channel->basic_qos(null, 20, false);

$handler = static function (AMQPMessage $message) use ($db): void {
    $event = PaymentEventEnvelope::fromMessage(
        $message->getBody(),
        (string) ($message->get('message_id') ?? ''),
        (string) ($message->get('type') ?? ''),
    );
    $eventId = $event->eventId;
    $eventType = $event->eventType;
    $tenantId = $event->tenantId;
    $orderId = $event->orderId;
    $payloadHash = $event->payloadHash;
    $paymentStatus = match ($eventType) {
        'PaymentAuthorized' => 'authorized',
        'PaymentCaptured' => 'captured',
        'PaymentFailed' => 'failed',
        'PaymentRefunded' => 'refunded',
        'PaymentPartiallyRefunded' => 'partially_refunded',
        'PaymentCancelled' => 'cancelled',
    };

    $db->transactional(static function ($db) use ($eventId, $eventType, $tenantId, $orderId, $paymentStatus, $payloadHash): void {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);
        $inserted = $db->fetchOne(
            'INSERT INTO order_payment_event_inbox (tenant_id, event_id, order_id, event_type, payload_hash)
             VALUES (:tenant, :event, :order, :type, :hash)
             ON CONFLICT (tenant_id, event_id) DO NOTHING RETURNING event_id',
            ['tenant' => $tenantId, 'event' => $eventId, 'order' => $orderId, 'type' => $eventType, 'hash' => $payloadHash],
        );
        if ($inserted === false) {
            $existing = $db->fetchAssociative(
                'SELECT payload_hash FROM order_payment_event_inbox WHERE tenant_id=:tenant AND event_id=:event',
                ['tenant' => $tenantId, 'event' => $eventId],
            );
            $existingHash = is_array($existing) ? trim((string) $existing['payload_hash']) : '';
            if ($existingHash === str_repeat('0', 64)) {
                $db->executeStatement(
                    'UPDATE order_payment_event_inbox SET payload_hash=:hash WHERE tenant_id=:tenant AND event_id=:event',
                    ['hash' => $payloadHash, 'tenant' => $tenantId, 'event' => $eventId],
                );
                return;
            }
            if ($existingHash === '' || !hash_equals($existingHash, $payloadHash)) {
                throw new UnexpectedValueException('A Payment eventId was reused with a different payload.');
            }
            return;
        }

        $exists = $db->fetchAssociative(
            'SELECT status, payment_status FROM orders WHERE tenant_id = :tenant AND order_id = :order FOR UPDATE',
            ['tenant' => $tenantId, 'order' => $orderId],
        );
        if (!is_array($exists)) {
            throw new RuntimeException('Payment event references an unknown order; message will be retried.');
        }
        $currentStatus = (string) $exists['payment_status'];
        $allowed = match ($currentStatus) {
            'not_started' => ['authorized', 'captured', 'failed', 'cancelled'],
            'authorized' => ['captured', 'failed', 'partially_refunded', 'refunded', 'cancelled'],
            'failed' => ['authorized', 'captured', 'cancelled'],
            'captured' => ['partially_refunded', 'refunded'],
            'partially_refunded' => ['partially_refunded', 'refunded'],
            default => [],
        };
        if (!in_array($paymentStatus, $allowed, true)) {
            return;
        }
        $updated = $db->executeStatement(
            'UPDATE orders SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant AND order_id = :order',
            ['status' => $paymentStatus, 'tenant' => $tenantId, 'order' => $orderId],
        );
        if ($updated !== 1) {
            throw new RuntimeException('Order payment state could not be updated.');
        }
        if ($paymentStatus === 'authorized' && $exists['status'] === 'accepted') {
            $db->executeStatement(
                "UPDATE orders SET status = 'contract_pending' WHERE tenant_id = :tenant AND order_id = :order AND status = 'accepted'",
                ['tenant' => $tenantId, 'order' => $orderId],
            );
            $db->executeStatement(
                "INSERT INTO order_history (tenant_id, order_id, from_status, to_status, actor, reason, occurred_at)
                 VALUES (:tenant, :order, 'accepted', 'contract_pending', 'system:payment-service', 'PaymentAuthorized', CURRENT_TIMESTAMP)",
                ['tenant' => $tenantId, 'order' => $orderId],
            );
        }
        $outboxEvent = 'Order' . match ($paymentStatus) {
            'authorized' => 'PaymentAuthorized',
            'captured' => 'PaymentCaptured',
            'failed' => 'PaymentFailed',
            'refunded' => 'PaymentRefunded',
            'partially_refunded' => 'PaymentPartiallyRefunded',
            'cancelled' => 'PaymentCancelled',
        };
        $payload = [
            'eventId' => Uuid::v7()->toRfc4122(),
            'eventType' => $outboxEvent,
            'schemaVersion' => 1,
            'producer' => 'order-service',
            'aggregate' => ['type' => 'Order', 'id' => $orderId],
            'tenantId' => $tenantId,
            'occurredAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'data' => ['orderId' => $orderId, 'paymentStatus' => $paymentStatus, 'paymentEventId' => $eventId],
        ];
        $db->executeStatement(
            'INSERT INTO order_outbox (event_id, tenant_id, order_id, event_type, payload, occurred_at) VALUES (:id, :tenant, :order, :type, CAST(:payload AS JSONB), :occurred)',
            [
                'id' => $payload['eventId'], 'tenant' => $tenantId, 'order' => $orderId,
                'type' => $outboxEvent, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'occurred' => $payload['occurredAt'],
            ],
        );
    });
};

$channel->basic_consume($queue, '', false, false, false, false, static function (AMQPMessage $message) use ($handler, $channel): void {
    try {
        $handler($message);
        $channel->basic_ack($message->getDeliveryTag());
    } catch (JsonException|InvalidArgumentException|UnexpectedValueException $exception) {
        fwrite(STDERR, 'Dead-lettering invalid Payment event: ' . $exception->getMessage() . PHP_EOL);
        $channel->basic_nack($message->getDeliveryTag(), false, false);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Payment event processing failed; requeueing: ' . $exception->getMessage() . PHP_EOL);
        $channel->basic_nack($message->getDeliveryTag(), false, true);
    }
});

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
