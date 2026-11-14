<?php
declare(strict_types=1);
use App\Infrastructure\Audit\AuditRepository;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
require dirname(__DIR__) . '/vendor/autoload.php';
$databaseUrl = getenv('AUDIT_DATABASE_URL') ?: '';
if ($databaseUrl === '') { throw new RuntimeException('AUDIT_DATABASE_URL is required.'); }
$eventId = 'audit-smoke-' . bin2hex(random_bytes(8));
$connection = new AMQPStreamConnection(getenv('RABBITMQ_HOST') ?: 'rabbitmq', (int) (getenv('RABBITMQ_PORT') ?: 5672), getenv('RABBITMQ_USER') ?: 'autoflow', getenv('RABBITMQ_PASSWORD') ?: 'autoflow', getenv('RABBITMQ_VHOST') ?: '/', false, 'PLAIN', null, 'en_US', 3.0, 3.0, null, true, 30);
$channel = $connection->channel();
$channel->exchange_declare('autoflow.order.events', 'topic', false, true, false);
$channel->confirm_select();
$envelope = ['eventId'=>$eventId,'eventType'=>'audit.smoke.test','tenantId'=>'tenant-demo','aggregateType'=>'audit-smoke-test','aggregateId'=>$eventId,'occurredAt'=>gmdate('c'),'payload'=>['source'=>'quality-workflow','status'=>'published']];
try {
    $channel->basic_publish(new AMQPMessage(json_encode($envelope, JSON_THROW_ON_ERROR), ['content_type'=>'application/json','delivery_mode'=>AMQPMessage::DELIVERY_MODE_PERSISTENT,'message_id'=>$eventId,'type'=>'audit.smoke.test']), 'autoflow.order.events', 'audit.smoke.test', true);
    $channel->wait_for_pending_acks_returns(5.0);
} finally {
    if ($channel->is_open()) { $channel->close(); }
    if ($connection->isConnected()) { $connection->close(); }
}
$repository = new AuditRepository($databaseUrl);
$deadline = microtime(true) + 20;
do {
    if ($repository->find('tenant-demo', $eventId) !== null) {
        fwrite(STDOUT, "Audit RabbitMQ smoke test passed: " . $eventId . PHP_EOL);
        exit(0);
    }
    usleep(250_000);
} while (microtime(true) < $deadline);
fwrite(STDERR, "Audit event was not persisted by the consumer within 20 seconds: " . $eventId . PHP_EOL);
exit(1);
