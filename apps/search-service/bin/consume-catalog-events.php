<?php
declare(strict_types=1);

use App\Infrastructure\Elasticsearch\ElasticsearchClient;
use App\Infrastructure\Indexing\VehicleCatalogueReindexer;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('CATALOG_DATABASE_URL') ?: '';
$parts = parse_url($databaseUrl);
if ($databaseUrl === '' || !is_array($parts) || !isset($parts['host'], $parts['path'], $parts['user'])) {
    throw new RuntimeException('A valid read-only CATALOG_DATABASE_URL is required.');
}
$dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, ltrim($parts['path'], '/'));
$pdo = new PDO($dsn, rawurldecode($parts['user']), rawurldecode($parts['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$http = HttpClient::create(['timeout' => 60]);
$elasticsearchUrl = getenv('ELASTICSEARCH_URL') ?: 'http://elasticsearch:9200';
$alias = getenv('SEARCH_VEHICLE_INDEX') ?: 'autoflow-vehicles';
$inbox = new ElasticsearchClient($http, $elasticsearchUrl, $alias);
$inbox->ensureInboxIndex();
$reindexer = new VehicleCatalogueReindexer($pdo, $http, $elasticsearchUrl, $alias);

$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST') ?: 'rabbitmq',
    (int) (getenv('RABBITMQ_PORT') ?: 5672),
    getenv('RABBITMQ_USER') ?: 'autoflow',
    getenv('RABBITMQ_PASSWORD') ?: 'autoflow',
    getenv('RABBITMQ_VHOST') ?: '/',
    false, 'PLAIN', null, 'en_US', 3.0, 3.0, null, true, 30,
);
$channel = $connection->channel();
$exchange = 'autoflow.catalog.events';
$retryExchange = 'autoflow.search.retry';
$deadExchange = 'autoflow.search.dlx';
$queue = 'autoflow.search.catalog.sync';
$retryQueue = 'autoflow.search.catalog.retry';
$deadQueue = 'autoflow.search.catalog.dlq';

$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->exchange_declare($retryExchange, 'direct', false, true, false);
$channel->exchange_declare($deadExchange, 'direct', false, true, false);
$channel->queue_declare($queue, false, true, false, false);
$channel->queue_bind($queue, $exchange, '#');
$channel->queue_declare($retryQueue, false, true, false, false, false, new AMQPTable([
    'x-message-ttl' => 15000,
    'x-dead-letter-exchange' => $exchange,
    'x-dead-letter-routing-key' => 'catalog.sync',
]));
$channel->queue_bind($retryQueue, $retryExchange, 'catalog.retry');
$channel->queue_declare($deadQueue, false, true, false, false);
$channel->queue_bind($deadQueue, $deadExchange, 'catalog.failed');
$channel->queue_bind($queue, $exchange, 'catalog.sync');
$channel->basic_qos(0, 1, false);

$publish = static function (AMQPMessage $original, string $exchangeName, string $routingKey, int $retryCount) use ($channel): void {
    $properties = $original->get_properties();
    $headers = $properties['application_headers'] ?? new AMQPTable();
    $native = $headers instanceof AMQPTable ? $headers->getNativeData() : [];
    $native['x-autoflow-retries'] = $retryCount;
    $properties['application_headers'] = new AMQPTable($native);
    $properties['delivery_mode'] = AMQPMessage::DELIVERY_MODE_PERSISTENT;
    $channel->basic_publish(new AMQPMessage($original->getBody(), $properties), $exchangeName, $routingKey, true);
};

$callback = static function (AMQPMessage $message) use ($inbox, $reindexer, $publish): void {
    $body = $message->getBody();
    $properties = $message->get_properties();
    $eventType = (string) ($properties['type'] ?? 'unknown');
    $eventId = (string) ($properties['message_id'] ?? '');
    if ($eventId === '') {
        $decodedForId = json_decode($body, true);
        $eventId = is_array($decodedForId) ? (string) ($decodedForId['eventId'] ?? $decodedForId['event_id'] ?? '') : '';
    }
    if ($eventId === '') {
        $eventId = hash('sha256', $body);
    }

    try {
        if ($inbox->hasProcessedEvent($eventId)) {
            $message->ack();
            return;
        }
        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new RuntimeException('Catalogue event body must be a JSON object.');
        }

        // Rebuild from the authoritative read model so parent updates and unpublishing
        // are reflected as well as direct variant changes. Incremental updates follow later.
        $count = $reindexer->rebuild();
        $inbox->markEventProcessed($eventId, $eventType);
        error_log(sprintf('[Search Sync] Event %s (%s) applied; %d published variants indexed.', $eventId, $eventType, $count));
        $message->ack();
    } catch (Throwable $error) {
        error_log(sprintf('[Search Sync] Event %s failed: %s', $eventId, $error->getMessage()));
        $headers = $properties['application_headers'] ?? new AMQPTable();
        $native = $headers instanceof AMQPTable ? $headers->getNativeData() : [];
        $retries = (int) ($native['x-autoflow-retries'] ?? 0);
        try {
            if ($retries < 5) {
                $publish($message, 'autoflow.search.retry', 'catalog.retry', $retries + 1);
            } else {
                $publish($message, 'autoflow.search.dlx', 'catalog.failed', $retries);
            }
            $message->ack();
        } catch (Throwable $publishError) {
            error_log('[Search Sync] Could not route failed message; requeueing: ' . $publishError->getMessage());
            $message->nack(true);
        }
    }
};

error_log('[Search Sync] Waiting for catalogue events; queue=' . $queue);
$channel->basic_consume($queue, 'autoflow-search-sync', false, false, false, false, $callback);
try {
    while ($channel->is_consuming()) {
        $channel->wait();
    }
} finally {
    $channel->close();
    $connection->close();
}
