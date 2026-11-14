<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

require dirname(__DIR__) . '/vendor/autoload.php';

$publisherKey = getenv('MERCURE_PUBLISHER_JWT_KEY') ?: '';
$hubUrl = rtrim(getenv('MERCURE_URL') ?: 'http://mercure/.well-known/mercure', '/');
$topicBase = rtrim(getenv('AUTOFLOW_MERCURE_TOPIC_BASE') ?: 'https://autoflow.local/tenants', '/');
if (strlen($publisherKey) < 32) {
    throw new RuntimeException('MERCURE_PUBLISHER_JWT_KEY must contain at least 32 characters.');
}
if (!extension_loaded('curl')) {
    throw new RuntimeException('The cURL extension is required.');
}

function b64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function publisherToken(string $key, string $topic): string
{
    $now = time();
    $header = b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
    $payload = b64url(json_encode([
        'iat' => $now,
        'exp' => $now + 60,
        'mercure' => ['publish' => [$topic]],
    ], JSON_THROW_ON_ERROR));
    $signature = b64url(hash_hmac('sha256', $header . '.' . $payload, $key, true));

    return $header . '.' . $payload . '.' . $signature;
}

function nestedString(array $data, array $paths): ?string
{
    foreach ($paths as $path) {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;
                break;
            }
            $value = $value[$segment];
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return null;
}

function publishMercure(string $hubUrl, string $key, string $topic, array $data): void
{
    $form = http_build_query([
        'topic' => $topic,
        'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'private' => 'on',
    ]);
    $curl = curl_init($hubUrl);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize Mercure HTTP client.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $form,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . publisherToken($key, $topic),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Mercure publish failed (' . $status . '): ' . ($error ?: mb_substr((string) $body, 0, 300)));
    }
}

$exchanges = [
    'autoflow.events',
    'autoflow.order.events',
    'autoflow.payment.events',
    'autoflow.contract.events',
    'autoflow.fulfillment.events',
    'autoflow.inventory.events',
    'autoflow.vehicle-reservation.events',
    'autoflow.reservation.events',
    'autoflow.crm.events',
    'autoflow.billing.events',
    'autoflow.catalog.events',
];

$connect = static function () use ($exchanges): array {
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
    $channel = $connection->channel();
    $channel->exchange_declare('autoflow.mercure.dlx', 'direct', false, true, false);
    $channel->queue_declare('autoflow.mercure.failed', false, true, false, false);
    $channel->queue_bind('autoflow.mercure.failed', 'autoflow.mercure.dlx', 'failed');
    $channel->queue_declare('autoflow.mercure.bridge', false, true, false, false, false, new AMQPTable([
        'x-dead-letter-exchange' => 'autoflow.mercure.dlx',
        'x-dead-letter-routing-key' => 'failed',
    ]));
    foreach ($exchanges as $exchange) {
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_bind('autoflow.mercure.bridge', $exchange, '#');
    }
    $channel->basic_qos(0, 20, false);

    return [$connection, $channel];
};

fwrite(STDOUT, "AutoFlow RabbitMQ → Mercure bridge started.\n");
while (true) {
    $connection = null;
    try {
        [$connection, $channel] = $connect();
        $channel->basic_consume('autoflow.mercure.bridge', '', false, false, false, false,
            static function (AMQPMessage $message) use ($publisherKey, $hubUrl, $topicBase, $channel): void {
                try {
                    $envelope = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($envelope) || array_is_list($envelope)) {
                        throw new UnexpectedValueException('Event envelope must be a JSON object.');
                    }
                    $headers = $message->get('application_headers');
                    $headerValues = $headers instanceof AMQPTable ? $headers->getNativeData() : [];
                    $tenant = nestedString($envelope, ['tenantId', 'tenant_id', 'metadata.tenantId', 'context.tenantId'])
                        ?? (is_string($headerValues['tenantId'] ?? null) ? trim($headerValues['tenantId']) : null)
                        ?? (is_string($headerValues['tenant_id'] ?? null) ? trim($headerValues['tenant_id']) : null);
                    if ($tenant === null || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $tenant)) {
                        throw new UnexpectedValueException('Event has no valid tenant identifier; refusing cross-tenant publication.');
                    }

                    $eventType = (string) ($envelope['eventType'] ?? $envelope['event_type'] ?? $message->get('type') ?? 'BusinessEvent');
                    $eventId = (string) ($envelope['eventId'] ?? $envelope['event_id'] ?? $message->get('message_id') ?? bin2hex(random_bytes(12)));
                    $aggregate = is_array($envelope['aggregate'] ?? null) ? $envelope['aggregate'] : [];
                    $topic = $topicBase . '/' . rawurlencode($tenant) . '/events';
                    $publicData = [
                        'id' => $eventId,
                        'type' => mb_substr($eventType, 0, 120),
                        'tenantId' => $tenant,
                        'occurredAt' => (string) ($envelope['occurredAt'] ?? $envelope['occurred_at'] ?? gmdate(DATE_ATOM)),
                        'aggregateType' => isset($aggregate['type']) ? mb_substr((string) $aggregate['type'], 0, 80) : null,
                        'aggregateId' => isset($aggregate['id']) ? mb_substr((string) $aggregate['id'], 0, 128) : null,
                        'message' => mb_substr($eventType . (isset($aggregate['id']) ? ' · ' . (string) $aggregate['id'] : ''), 0, 300),
                        'producer' => mb_substr((string) ($headerValues['producer'] ?? 'autoflow'), 0, 80),
                    ];
                    publishMercure($hubUrl, $publisherKey, $topic, $publicData);
                    $message->ack();
                } catch (UnexpectedValueException $exception) {
                    fwrite(STDERR, 'Rejected event: ' . $exception->getMessage() . "\n");
                    $message->reject(false);
                } catch (Throwable $exception) {
                    fwrite(STDERR, 'Mercure delivery failed: ' . $exception->getMessage() . "\n");
                    $message->nack(false, true);
                }
            }
        );
        while ($channel->is_consuming()) {
            $channel->wait(null, false, 5);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Bridge connection failed; retrying: ' . $exception->getMessage() . "\n");
        sleep(5);
    } finally {
        if ($connection instanceof AMQPStreamConnection && $connection->isConnected()) {
            try {
                $connection->close();
            } catch (Throwable) {
            }
        }
    }
}
