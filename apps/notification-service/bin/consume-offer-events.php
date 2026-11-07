<?php

declare(strict_types=1);

use AutoFlow\Notification\Application\HandleOfferPublished;
use AutoFlow\Notification\Infrastructure\Identity\KeycloakUserDirectory;
use AutoFlow\Notification\Infrastructure\Mail\OfferPublishedEmailTemplate;
use AutoFlow\Notification\Infrastructure\Mail\SmtpEmailSender;
use AutoFlow\Notification\Infrastructure\Messaging\RabbitMqOfferEventConsumer;
use AutoFlow\Notification\Infrastructure\Observability\StructuredLogger;
use AutoFlow\Notification\Infrastructure\Pdf\OfferPdfGenerator;
use AutoFlow\Notification\Infrastructure\Persistence\NotificationDeliveryRepository;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$logger = new StructuredLogger();

$databaseUrl = getenv('NOTIFICATION_DATABASE_URL') ?: 'postgresql://autoflow:autoflow@postgres:5432/autoflow';
$db = parse_url($databaseUrl);
if (!is_array($db) || !in_array($db['scheme'] ?? null, ['postgres', 'postgresql'], true)) {
    throw new RuntimeException('NOTIFICATION_DATABASE_URL must be a PostgreSQL URL.');
}

$dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s',
    $db['host'] ?? 'postgres',
    $db['port'] ?? 5432,
    ltrim((string) ($db['path'] ?? '/autoflow'), '/'),
);
$pdo = new PDO(
    $dsn,
    rawurldecode((string) ($db['user'] ?? 'autoflow')),
    rawurldecode((string) ($db['pass'] ?? 'autoflow')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);

$deliveries = new NotificationDeliveryRepository($pdo);
$http = HttpClient::create();
$users = new KeycloakUserDirectory(
    $http,
    getenv('KEYCLOAK_BASE_URL') ?: 'http://keycloak:8080',
    getenv('KEYCLOAK_REALM') ?: 'autoflow',
    getenv('KEYCLOAK_NOTIFICATION_CLIENT_ID') ?: 'autoflow-notification',
    getenv('KEYCLOAK_NOTIFICATION_CLIENT_SECRET') ?: 'autoflow-local-notification-secret',
);
$mailer = new SmtpEmailSender(
    getenv('MAILER_DSN') ?: 'smtp://mailpit:1025',
    getenv('MAIL_FROM_ADDRESS') ?: 'offers@autoflow.local',
    getenv('MAIL_FROM_NAME') ?: 'AutoFlow Mobility',
    new OfferPublishedEmailTemplate(),
    new OfferPdfGenerator(),
);
$handler = new HandleOfferPublished($users, $mailer, $deliveries);

while (true) {
    $connection = null;
    try {
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
            70.0,
            null,
            true,
            30,
        );
        $consumer = new RabbitMqOfferEventConsumer($connection->channel(), $handler, $logger, deliveries: $deliveries);
        $consumer->run();
    } catch (Throwable $exception) {
        $logger->error('Notification worker connection failed; retrying.', ['error' => $exception->getMessage()]);
        usleep(5_000_000);
    } finally {
        if ($connection instanceof AMQPStreamConnection && $connection->isConnected()) {
            try {
                $connection->close();
            } catch (Throwable) {
            }
        }
    }
}
