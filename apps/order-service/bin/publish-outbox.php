<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('ORDER_PUBLISHER_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('ORDER_PUBLISHER_DATABASE_URL is required.');
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

$connect = static function (): array {
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
    $channel->exchange_declare('autoflow.order.events', 'topic', false, true, false);
    $channel->queue_declare('autoflow.order.events', false, true, false, false);
    $channel->queue_bind('autoflow.order.events', 'autoflow.order.events', '#');
    $channel->confirm_select();
    return [$connection, $channel];
};

[$connection, $channel] = $connect();
$meter = Globals::meterProvider()->getMeter('autoflow.order-outbox-publisher', '1.0.0');
$publishedEvents = $meter->createCounter('order_outbox.published', '{event}', 'Outbox events published');
$publishFailures = $meter->createCounter(
    'order_outbox.publish_failures',
    '{attempt}',
    'Outbox publication attempts that failed',
);

try {
    while (true) {
        $event = $db->fetchAssociative('SELECT * FROM public.order_outbox_claim()');
        if (!is_array($event)) {
            try {
                $connection->checkHeartBeat();
            } catch (Throwable) {
                try {
                    if ($channel->is_open()) {
                        $channel->close();
                    }
                } catch (Throwable) {
                }
                try {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                } catch (Throwable) {
                }
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
        $eventEnvelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $traceContext = is_array($eventEnvelope['traceContext'] ?? null)
            ? $eventEnvelope['traceContext']
            : [];
        unset($eventEnvelope['traceContext']);
        $payload = json_encode($eventEnvelope, JSON_THROW_ON_ERROR);
        $spanBuilder = Globals::tracerProvider()->getTracer('autoflow.order-service', '1.0.0')
            ->spanBuilder('messaging.publish')
            ->setSpanKind(SpanKind::KIND_PRODUCER);
        if ($traceContext !== []) {
            $spanBuilder->setParent(TraceContextPropagator::getInstance()->extract($traceContext));
        }
        $span = $spanBuilder->startSpan();
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
            $db->executeQuery('SELECT public.order_outbox_mark_published(:id)', ['id' => $eventId]);
            $publishedEvents->add(1, ['event_type' => $eventType]);
        } catch (Throwable $error) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($error);
            $db->executeQuery(
                'SELECT public.order_outbox_schedule_retry(:id, :error)',
                ['id' => $eventId, 'error' => mb_substr($error->getMessage(), 0, 500)],
            );
            $publishFailures->add(1, ['event_type' => $eventType]);
            try {
                $connection->checkHeartBeat();
            } catch (Throwable) {
                try {
                    if ($channel->is_open()) {
                        $channel->close();
                    }
                } catch (Throwable) {
                }
                try {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                } catch (Throwable) {
                }
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
