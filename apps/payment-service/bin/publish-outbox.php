<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = getenv('PAYMENT_PUBLISHER_DATABASE_URL') ?: '';
if ($url === '') {
    throw new RuntimeException('PAYMENT_PUBLISHER_DATABASE_URL is required.');
}
$db = DriverManager::getConnection(['url' => $url]);

$connect = static function (): array {
    $connection = new AMQPStreamConnection(
        getenv('RABBITMQ_HOST') ?: 'rabbitmq',
        (int) (getenv('RABBITMQ_PORT') ?: 5672),
        getenv('RABBITMQ_USER') ?: 'autoflow',
        getenv('RABBITMQ_PASSWORD') ?: 'autoflow',
        getenv('RABBITMQ_VHOST') ?: '/',
        false, 'PLAIN', null, 'en_US', 3.0, 3.0, null, true, 30,
    );
    $channel = $connection->channel();
    $channel->exchange_declare('autoflow.payment.events', 'topic', false, true, false);
    $channel->queue_declare('autoflow.payment.events', false, true, false, false);
    $channel->queue_bind('autoflow.payment.events', 'autoflow.payment.events', '#');
    $channel->confirm_select();
    return [$connection, $channel];
};

[$connection, $channel] = $connect();
try {
    while (true) {
        $event = $db->fetchAssociative('SELECT * FROM public.payment_outbox_claim()');
        if (!is_array($event)) {
            try {
                $connection->checkHeartBeat();
            } catch (Throwable) {
                try { if ($channel->is_open()) { $channel->close(); } } catch (Throwable) {}
                try { if ($connection->isConnected()) { $connection->close(); } } catch (Throwable) {}
                [$connection, $channel] = $connect();
            }
            usleep(500000);
            continue;
        }

        $eventId = (string) $event['event_id'];
        $eventType = (string) $event['event_type'];
        $payload = is_string($event['payload'])
            ? $event['payload']
            : json_encode($event['payload'], JSON_THROW_ON_ERROR);
        try {
            $message = new AMQPMessage($payload, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'type' => $eventType,
            ]);
            $channel->basic_publish($message, 'autoflow.payment.events', $eventType, true);
            $channel->wait_for_pending_acks_returns(5);
            $db->executeQuery('SELECT public.payment_outbox_mark_published(:id)', ['id' => $eventId]);
        } catch (Throwable $error) {
            $db->executeQuery(
                'SELECT public.payment_outbox_schedule_retry(:id, :error)',
                ['id' => $eventId, 'error' => mb_substr($error->getMessage(), 0, 500)],
            );
            try {
                $connection->checkHeartBeat();
            } catch (Throwable) {
                try { if ($channel->is_open()) { $channel->close(); } } catch (Throwable) {}
                try { if ($connection->isConnected()) { $connection->close(); } } catch (Throwable) {}
                [$connection, $channel] = $connect();
            }
        }
    }
} finally {
    $channel->close();
    $connection->close();
}
