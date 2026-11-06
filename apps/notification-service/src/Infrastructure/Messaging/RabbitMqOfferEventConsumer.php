<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Infrastructure\Messaging;

use AutoFlow\Notification\Application\HandleOfferPublished;
use AutoFlow\Notification\Domain\Notification\OfferPublishedNotification;
use AutoFlow\Notification\Infrastructure\Observability\StructuredLogger;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final readonly class RabbitMqOfferEventConsumer
{
    public function __construct(
        private AMQPChannel $channel,
        private HandleOfferPublished $handler,
        private StructuredLogger $logger,
        private string $exchange = 'autoflow.events',
        private string $queue = 'notification.offer-published',
        private int $maxAttempts = 5,
    ) {
    }

    public function run(): never
    {
        $this->channel->exchange_declare($this->exchange, 'topic', false, true, false);
        $this->channel->exchange_declare('autoflow.notifications.retry', 'direct', false, true, false);
        $this->channel->exchange_declare('autoflow.notifications.dlx', 'direct', false, true, false);

        $this->channel->queue_declare('notification.offer-published.retry', false, true, false, false, false, new AMQPTable([
            'x-message-ttl' => 30000,
            'x-dead-letter-exchange' => $this->exchange,
            'x-dead-letter-routing-key' => 'offer.published',
        ]));
        $this->channel->queue_bind('notification.offer-published.retry', 'autoflow.notifications.retry', 'offer.published.retry');

        $this->channel->queue_declare('notification.failed', false, true, false, false);
        $this->channel->queue_bind('notification.failed', 'autoflow.notifications.dlx', 'notification.failed');

        $this->channel->queue_declare($this->queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => 'autoflow.notifications.dlx',
            'x-dead-letter-routing-key' => 'notification.failed',
        ]));
        $this->channel->queue_bind($this->queue, $this->exchange, 'offer.published');
        $this->channel->basic_qos(null, 1, null);

        $this->channel->basic_consume($this->queue, '', false, false, false, false, function (AMQPMessage $message): void {
            $eventId = 'unknown';
            $attempt = 0;

            try {
                $headers = $message->get('application_headers');
                if ($headers instanceof AMQPTable) {
                    $native = $headers->getNativeData();
                    $attempt = (int) ($native['x-autoflow-attempt'] ?? 0);
                }

                $envelope = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($envelope)) {
                    throw new \UnexpectedValueException('RabbitMQ offer event body must be a JSON object.');
                }
                $eventId = (string) ($envelope['eventId'] ?? 'unknown');
                $notification = OfferPublishedNotification::fromEnvelope($envelope);
                $sent = ($this->handler)($notification);

                $this->logger->info($sent ? 'Offer publication email sent.' : 'Duplicate offer event skipped.', [
                    'event_id' => $eventId,
                    'offer_id' => $notification->offerId,
                    'attempt' => $attempt,
                ]);
                $message->ack();
            } catch (Throwable $exception) {
                $dead = ($attempt + 1) >= $this->maxAttempts;
                $this->logger->error('Offer notification delivery failed.', [
                    'event_id' => $eventId,
                    'attempt' => $attempt + 1,
                    'dead_lettered' => $dead,
                    'error' => $exception->getMessage(),
                ]);

                if ($eventId !== 'unknown') {
                    // Delivery state is also persisted by the application handler/repository.
                }

                $headers = new AMQPTable(['x-autoflow-attempt' => $attempt + 1]);
                $retry = new AMQPMessage($message->getBody(), [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => $headers,
                ]);

                if ($dead) {
                    $this->channel->basic_publish($retry, 'autoflow.notifications.dlx', 'notification.failed');
                } else {
                    $this->channel->basic_publish($retry, 'autoflow.notifications.retry', 'offer.published.retry');
                }
                $message->ack();
            }
        });

        $this->logger->info('Notification consumer started.', ['queue' => $this->queue]);
        while ($this->channel->is_consuming()) {
            $this->channel->wait(null, false, 5);
        }

        throw new \RuntimeException('RabbitMQ consumer stopped unexpectedly.');
    }
}