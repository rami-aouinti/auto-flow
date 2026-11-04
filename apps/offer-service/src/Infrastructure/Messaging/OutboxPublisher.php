<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Infrastructure\Persistence\PostgresConnection;
use PDO;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

final readonly class OutboxPublisher
{
    public function __construct(
        private PostgresConnection $connection,
        private AMQPChannel $channel,
        private string $exchange = 'autoflow.events',
        private string $queue = 'offer.events',
    ) {
        $this->channel->exchange_declare($this->exchange, 'topic', false, true, false);
        $this->channel->queue_declare($this->queue, false, true, false, false);
        $this->channel->queue_bind($this->queue, $this->exchange, 'offer.#');
        $this->channel->confirm_select();
    }

    /**
     * Publishes at most one event. Returns false when the outbox is empty.
     *
     * The database row remains locked until RabbitMQ confirms the durable message.
     * A crash after broker acknowledgement but before commit can cause a duplicate;
     * consumers must therefore deduplicate by eventId.
     */
    public function publishNext(): bool
    {
        $connection = $this->connection->get();
        $connection->beginTransaction();

        try {
            $statement = $connection->query(<<<'SQL'
                SELECT event_id, event_type, payload
                FROM offer_outbox_events
                WHERE published_at IS NULL
                  AND next_attempt_at <= NOW()
                ORDER BY occurred_at ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
                SQL);
            $event = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($event)) {
                $connection->commit();

                return false;
            }

            try {
                $payload = (string) $event['payload'];
                $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($envelope) || !isset($envelope['eventId'], $envelope['eventType'])) {
                    throw new \UnexpectedValueException('Outbox event envelope is invalid.');
                }

                $eventType = (string) $event['event_type'];
                $suffix = strtolower((string) preg_replace('/^Offer/', '', $eventType));
                $message = new AMQPMessage($payload, [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => (string) $event['event_id'],
                    'type' => $eventType,
                    'timestamp' => time(),
                ]);

                $this->channel->basic_publish($message, $this->exchange, 'offer.' . $suffix);
                $this->channel->wait_for_pending_acks_returns(5.0);

                $mark = $connection->prepare(
                    'UPDATE offer_outbox_events SET published_at = NOW(), last_error = NULL WHERE event_id = :event_id',
                );
                $mark->execute(['event_id' => $event['event_id']]);
            } catch (Throwable $publishFailure) {
                $retry = $connection->prepare(<<<'SQL'
                    UPDATE offer_outbox_events
                    SET attempt_count = attempt_count + 1,
                        next_attempt_at = NOW()
                            + (LEAST(300, POWER(2, LEAST(attempt_count + 1, 8))) * INTERVAL '1 second'),
                        last_error = :last_error
                    WHERE event_id = :event_id
                    SQL);
                $retry->execute([
                    'event_id' => $event['event_id'],
                    'last_error' => mb_substr($publishFailure->getMessage(), 0, 2000),
                ]);
                error_log(sprintf(
                    '[Offer Outbox] Publish failed for event %s: %s',
                    (string) $event['event_id'],
                    $publishFailure->getMessage(),
                ));
            }

            $connection->commit();

            return true;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }
}
