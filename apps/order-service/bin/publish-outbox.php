<?php

declare(strict_types=1);

use Doctrine\\DBAL\\DriverManager;
use PhpAmqpLib\\Connection\\AMQPStreamConnection;
use PhpAmqpLib\\Message\\AMQPMessage;
use PhpAmqpLib\\Wire\\AMQPTable;
use OpenTelemetry\\API\\Globals;
use OpenTelemetry\\API\\Trace\\Propagation\\TraceContextPropagator;
use OpenTelemetry\\API\\Trace\\SpanKind;
use OpenTelemetry\\API\\Trace\\StatusCode;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('ORDER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('ORDER_DATABASE_URL is required.');
}
$db = DriverManager::getConnection(['url' => $databaseUrl]);

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
    $channel->exchange_declare('autoflow.order.events', 'topic', false, true, false);
    $channel->confirm_select();
    return [$connection, $channel];
};

[$connection, $channel] = $connect();

try {
    while (true) {
        $event = $db->fetchAssociative('SELECT * FROM public.order_outbox_claim()');
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
        $payload = json_encode($event['payload'], JSON_THROW_ON_ERROR);
        $span = Globals::tracerProvider()->getTracer('autoflow.order-service', '1.0.0')
            ->spanBuilder('messaging.publish')->setSpanKind(SpanKind::KIND_PRODUCER)->startSpan();
        $scope = $span->activate();
        try {
            $span->setAttribute('messaging.system', 'rabbitmq');
            $span->setAttribute('messaging.destination.name', 'autoflow.order.events');
            $span->setAttribute('messaging.message.id', $eventId);
            $span->setAttribute('messaging.message.type', $eventType);
            $headers = [];
            TraceContextPropagator::getInstance()->inject($headers);
            $message = new AMQPMessage($payload, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'type' => $eventType,
                'application_headers' => new AMQPTable($headers),
            ]);
            $channel->basic_publish($message, 'autoflow.order.events', $eventType, true);
            $channel->wait_for_pending_acks_returns(5);
            $db->executeStatement('SELECT public.order_outbox_mark_published(:id)', ['id' => $eventId]);
        } catch (Throwable $error) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($error);
            $db->executeStatement(
                'SELECT public.order_outbox_schedule_retry(:id, :error)',
                ['id' => $eventId, 'error' => mb_substr($error->getMessage(), 0, 500)],
            );
            try { $connection->checkHeartBeat(); } catch (Throwable) {
                try { if ($channel->is_open()) { $channel->close(); } } catch (Throwable) {}
                try { if ($connection->isConnected()) { $connection->close(); } } catch (Throwable) {}
                [$connection, $channel] = $connect();
            }
        } finally {
            $scope->detach();
            $span->end();
        }
    }
} finally {
    $channel->close();
    $connection->close();
}
