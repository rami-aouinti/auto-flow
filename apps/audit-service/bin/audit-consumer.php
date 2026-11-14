<?php
declare(strict_types=1);

use App\Application\AuditEventNormalizer;
use App\Application\AuditPayloadRedactor;
use App\Infrastructure\Audit\AuditRepository;
use App\Infrastructure\Messaging\AuditEventConsumer;
use PhpAmqpLib\Connection\AMQPStreamConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('AUDIT_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('AUDIT_DATABASE_URL is required.');
}

$parts = parse_url($databaseUrl);
if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
    throw new RuntimeException('AUDIT_DATABASE_URL must be a PostgreSQL URL.');
}

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

$repository = new AuditRepository($databaseUrl);
$consumer = new AuditEventConsumer(
    $channel,
    $repository,
    new AuditEventNormalizer(),
    new AuditPayloadRedactor(),
);

try {
    $consumer->run();
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
