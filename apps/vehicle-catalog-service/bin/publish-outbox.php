<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\PostgresConnection;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
if (!is_string($url) || $url === '') {
    throw new RuntimeException('DATABASE_URL is required.');
}
$db = (new PostgresConnection($url))->get();
$connectRabbit = static function (): array {
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
    $channel->exchange_declare('autoflow.catalog.events', 'topic', false, true, false);
    $channel->confirm_select();

    return [$connection, $channel];
};

[$connection, $channel] = $connectRabbit();


try {
    while (true) {
        $db->beginTransaction();
        $claim = $db->query(<<<'SQL'
            WITH candidate AS (
                SELECT event_id FROM catalog_outbox
                WHERE published_at IS NULL
                  AND next_attempt_at <= CURRENT_TIMESTAMP
                  AND (locked_until IS NULL OR locked_until < CURRENT_TIMESTAMP)
                ORDER BY occurred_at
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            )
            UPDATE catalog_outbox AS outbox
            SET locked_until = CURRENT_TIMESTAMP + INTERVAL '30 seconds',
                attempts = attempts + 1
            FROM candidate
            WHERE outbox.event_id = candidate.event_id
            RETURNING outbox.event_id, outbox.event_type, outbox.payload, outbox.attempts
            SQL);
        if (!$claim instanceof PDOStatement) {
            throw new RuntimeException('Unable to claim pending catalogue Outbox events.');
        }

        $event = $claim->fetch(PDO::FETCH_ASSOC);
        $db->commit();

        if (!is_array($event)) {
            try {
                // Keep the long-lived RabbitMQ connection alive while the Outbox is idle.
                $connection->checkHeartBeat();
            } catch (Throwable $heartbeatError) {
                error_log('[Catalog Outbox] Heartbeat failed; reconnecting: ' . $heartbeatError->getMessage());
                try {
                    if ($channel->is_open()) {
                        $channel->close();
                    }
                } catch (Throwable) {
                    // The channel may already be closed.
                }
                try {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                } catch (Throwable) {
                    // The connection may already be unavailable.
                }
                [$connection, $channel] = $connectRabbit();
                error_log('[Catalog Outbox] RabbitMQ connection restored.');
            }

            usleep(500000);
            continue;
        }

        $eventId = (string) ($event['event_id'] ?? '');
        $eventType = (string) ($event['event_type'] ?? '');
        $payload = $event['payload'] ?? null;

        if ($eventId === '' || $eventType === '' || !is_string($payload)) {
            throw new RuntimeException('The claimed catalogue Outbox event is malformed.');
        }

        $span = Globals::tracerProvider()
            ->getTracer('autoflow.vehicle-catalog-service', '1.0.0')
            ->spanBuilder('messaging.publish')
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->startSpan();
        $scope = $span->activate();

        try {
            $span->setAttribute('messaging.system', 'rabbitmq');
            $span->setAttribute('messaging.destination.name', 'autoflow.catalog.events');
            $span->setAttribute('messaging.message.id', $eventId);
            $span->setAttribute('messaging.message.type', $eventType);

            $traceHeaders = [];
            TraceContextPropagator::getInstance()->inject($traceHeaders);
            $message = new AMQPMessage($payload, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'type' => $eventType,
                'application_headers' => new AMQPTable($traceHeaders),
            ]);
            $channel->basic_publish($message, 'autoflow.catalog.events', $eventType, true);
            $channel->wait_for_pending_acks_returns(5);
            $done = $db->prepare(
                'UPDATE catalog_outbox '
                . 'SET published_at = CURRENT_TIMESTAMP, locked_until = NULL, last_error = NULL '
                . 'WHERE event_id = :id',
            );
            if (!$done instanceof PDOStatement) {
                throw new RuntimeException('Unable to acknowledge the published catalogue Outbox event.');
            }
            $done->execute(['id' => $eventId]);
        } catch (Throwable $error) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($error);
            $retry = $db->prepare(<<<'SQL'
                UPDATE catalog_outbox
                SET locked_until=NULL,
                    next_attempt_at = CURRENT_TIMESTAMP + make_interval(
                        secs => LEAST(300, POWER(2, LEAST(attempts, 8))::integer)
                    ),
                    last_error=:error
                WHERE event_id=:id
                SQL);
            if (!$retry instanceof PDOStatement) {
                throw new RuntimeException('Unable to schedule catalogue Outbox retry.', previous: $error);
            }
            $retry->execute(['id' => $eventId, 'error' => mb_substr($error->getMessage(), 0, 500)]);

            // If publishing failed because the broker connection went stale,
            // replace it before the next Outbox iteration.
            try {
                $connection->checkHeartBeat();
            } catch (Throwable $heartbeatError) {
                error_log('[Catalog Outbox] RabbitMQ connection lost; reconnecting: ' . $heartbeatError->getMessage());
                try {
                    if ($channel->is_open()) {
                        $channel->close();
                    }
                } catch (Throwable) {
                    // The channel may already be closed.
                }
                try {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                } catch (Throwable) {
                    // The connection may already be unavailable.
                }
                [$connection, $channel] = $connectRabbit();
                error_log('[Catalog Outbox] RabbitMQ connection restored.');
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
