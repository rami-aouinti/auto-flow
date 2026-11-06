<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('ORDER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('ORDER_DATABASE_URL is required.');
}
$db = DriverManager::getConnection(['url' => $databaseUrl]);
$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST') ?: 'rabbitmq',
    (int) (getenv('RABBITMQ_PORT') ?: 5672),
    getenv('RABBITMQ_USER') ?: 'autoflow',
    getenv('RABBITMQ_PASSWORD') ?: 'autoflow',
    getenv('RABBITMQ_VHOST') ?: '/',
    false, 'PLAIN', null, 'en_US', 3.0, 3.0, null, true, 30,
);
$channel = $connection->channel();
$exchange = 'autoflow.payment.events';
$queue = 'autoflow.order.payment-events';
$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->queue_declare($queue, false, true, false, false);
foreach (['PaymentAuthorized', 'PaymentCaptured', 'PaymentFailed', 'PaymentRefunded', 'PaymentCancelled'] as $eventType) {
    $channel->queue_bind($queue, $exchange, $eventType);
}
$channel->basic_qos(null, 20, null);

$handler = static function (AMQPMessage $message) use ($db): void {
    $envelope = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($envelope)) {
        throw new UnexpectedValueException('Payment event envelope must be a JSON object.');
    }
    $eventId = $envelope['eventId'] ?? $message->get('message_id');
    $eventType = $envelope['eventType'] ?? $message->get('type');
    $tenantId = $envelope['tenantId'] ?? null;
    $data = $envelope['data'] ?? [];
    $orderId = is_array($data) ? ($data['orderId'] ?? null) : null;
    if (!is_string($eventId) || !is_string($eventType) || !is_string($tenantId)
        || !is_string($orderId) || !Symfony\Component\Uid\Uuid::isValid($eventId)
        || !Symfony\Component\Uid\Uuid::isValid($orderId)
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1) {
        throw new UnexpectedValueException('Payment event is missing valid event, tenant or order identifiers.');
    }
    $paymentStatus = match ($eventType) {
        'PaymentAuthorized' => 'authorized',
        'PaymentCaptured' => 'captured',
        'PaymentFailed' => 'failed',
        'PaymentRefunded' => (($data['status'] ?? null) === 'partially_refunded' ? 'partially_refunded' : 'refunded'),
        'PaymentCancelled' => 'cancelled',
        default => null,
    };
    if ($paymentStatus === null) {
        return;
    }
    $db->transactional(static function ($db) use ($eventId, $eventType, $tenantId, $orderId, $paymentStatus): void {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenantId]);
        $inserted = $db->fetchOne(
            'INSERT INTO order_payment_event_inbox (tenant_id, event_id, order_id, event_type) VALUES (:tenant, :event, :order, :type) ON CONFLICT DO NOTHING RETURNING event_id',
            ['tenant' => $tenantId, 'event' => $eventId, 'order' => $orderId, 'type' => $eventType],
        );
        if ($inserted === false) {
            return;
        }
        $exists = $db->fetchOne(
            'SELECT status, payment_status FROM orders WHERE tenant_id = :tenant AND order_id = :order',
            ['tenant' => $tenantId, 'order' => $orderId],
        );
        if (!is_array($exists)) {
            throw new RuntimeException('Payment event references an unknown order; message will be retried.');
        }
        $currentStatus = (string) $exists['payment_status'];
        $allowed = match ($currentStatus) {
            'not_started' => ['authorized', 'failed', 'cancelled'],
            'authorized' => ['captured', 'failed', 'partially_refunded', 'refunded', 'cancelled'],
            'failed' => ['authorized', 'cancelled'],
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
            'cancelled' => 'PaymentCancelled',
        };
        $payload = [
            'eventId' => Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
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
        $message->ack();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Payment event processing failed: ' . $exception->getMessage() . PHP_EOL);
        $message->nack(false, true);
    }
});

try {
    while ($channel->is_consuming()) {
        $channel->wait();
    }
} finally {
    $channel->close();
    $connection->close();
}
