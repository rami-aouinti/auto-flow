<?php
declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require dirname(__DIR__) . '/vendor/autoload.php';

$url = getenv('CRM_PUBLISHER_DATABASE_URL') ?: '';
if ($url === '') {
    throw new RuntimeException('CRM_PUBLISHER_DATABASE_URL is required.');
}
$parts = parse_url($url);
if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
    throw new RuntimeException('A valid PostgreSQL URL is required.');
}
$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'host' => $parts['host'] ?? 'localhost',
    'port' => $parts['port'] ?? 5432,
    'user' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
    'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
    'dbname' => rawurldecode(ltrim($parts['path'] ?? '', '/')),
]);

$connection = null;
$channel = null;
$connect = static function () use (&$connection, &$channel): void {
    $connection = new AMQPStreamConnection(
        getenv('RABBITMQ_HOST') ?: 'rabbitmq',
        (int) (getenv('RABBITMQ_PORT') ?: 5672),
        getenv('RABBITMQ_USER') ?: 'autoflow',
        getenv('RABBITMQ_PASSWORD') ?: 'autoflow',
        getenv('RABBITMQ_VHOST') ?: '/',
        false, 'PLAIN', null, 'en_US', 3.0, 3.0, null, true, 30,
    );
    $channel = $connection->channel();
    $channel->exchange_declare('autoflow.crm.events', 'topic', false, true, false);
    $channel->confirm_select();
};
$connect();

try {
    while (true) {
        if (!$connection->isConnected() || !$channel->is_open()) {
            $connect();
        }
        $events = $db->fetchAllAssociative('SELECT * FROM public.crm_outbox_claim()');
        if ($events === []) {
            try { $connection->checkHeartBeat(); } catch (Throwable) { $connect(); }
            usleep(500000);
            continue;
        }
        foreach ($events as $event) {
            $eventId = (string) $event['event_id'];
            $tenantId = (string) $event['tenant_id'];
            $type = (string) $event['event_type'];
            $payload = is_string($event['payload']) ? $event['payload'] : json_encode($event['payload'], JSON_THROW_ON_ERROR);
            try {
                $message = new AMQPMessage($payload, [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $eventId,
                    'type' => $type,
                    'timestamp' => time(),
                    'application_headers' => new \PhpAmqpLib\Wire\AMQPTable(['tenant_id' => $tenantId]),
                ]);
                $channel->basic_publish($message, 'autoflow.crm.events', $type, true);
                $channel->wait_for_pending_acks_returns(5);
                $db->executeStatement('SELECT public.crm_outbox_mark_published(:tenant,:event)', ['tenant' => $tenantId, 'event' => $eventId]);
            } catch (Throwable $error) {
                $db->executeStatement('SELECT public.crm_outbox_schedule_retry(:tenant,:event,:error)', [
                    'tenant' => $tenantId, 'event' => $eventId, 'error' => mb_substr($error->getMessage(), 0, 500),
                ]);
                try { $connection->checkHeartBeat(); } catch (Throwable) { $connect(); }
            }
        }
    }
} finally {
    if ($channel instanceof \PhpAmqpLib\Channel\AMQPChannel && $channel->is_open()) { $channel->close(); }
    if ($connection instanceof AMQPStreamConnection && $connection->isConnected()) { $connection->close(); }
}
