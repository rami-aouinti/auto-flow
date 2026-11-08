<?php

declare(strict_types=1);

use App\Application\FulfillmentEventHandler;
use App\Application\IntegrationEvent;
use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('FULFILLMENT_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('FULFILLMENT_DATABASE_URL is required.');
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

$channel->exchange_declare('autoflow.payment.events', 'topic', false, true, false);
$channel->exchange_declare('autoflow.contract.events', 'topic', false, true, false);
$channel->exchange_declare('autoflow.fulfillment.dlx', 'topic', false, true, false);
$channel->queue_declare('autoflow.fulfillment.integration-events.dlq', false, true, false, false);
$channel->queue_bind('autoflow.fulfillment.integration-events.dlq', 'autoflow.fulfillment.dlx', '#');
$channel->queue_declare(
    'autoflow.fulfillment.integration-events',
    false,
    true,
    false,
    false,
    false,
    new AMQPTable(['x-dead-letter-exchange' => 'autoflow.fulfillment.dlx']),
);
$channel->queue_bind('autoflow.fulfillment.integration-events', 'autoflow.payment.events', 'PaymentCaptured');
$channel->queue_bind('autoflow.fulfillment.integration-events', 'autoflow.contract.events', 'contract.signed.v1');
$channel->basic_qos(null, 20, null);

$handler = new FulfillmentEventHandler($db);
$channel->basic_consume(
    'autoflow.fulfillment.integration-events',
    '',
    false,
    false,
    false,
    false,
    static function (AMQPMessage $message) use ($handler, $channel): void {
        try {
            $event = IntegrationEvent::fromJson($message->getBody());
            if (($message->get('type') ?? null) !== $event->eventType
                || ($message->get('message_id') ?? null) !== $event->eventId
            ) {
                throw new InvalidArgumentException('RabbitMQ message metadata does not match its event envelope.');
            }

            $handler->handle($event);
            $channel->basic_ack($message->getDeliveryTag());
        } catch (InvalidArgumentException|UnexpectedValueException $exception) {
            fwrite(STDERR, sprintf("Rejecting invalid fulfillment event: %s\n", $exception->getMessage()));
            $channel->basic_nack($message->getDeliveryTag(), false, false);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("Fulfillment event processing failed; requeueing: %s\n", $exception->getMessage()));
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
