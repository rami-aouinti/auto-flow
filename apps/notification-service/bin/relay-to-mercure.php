<?php

declare(strict_types=1);

use AutoFlow\Notification\Infrastructure\Messaging\MercureEvent;
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
        $detail = $error ?: mb_substr((string) $body, 0, 300);
        throw new RuntimeException('Mercure publish failed (' . $status . '): ' . $detail);
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
    $channel->exchange_declare('autoflow.mercure.retry', 'direct', false, true, false);
    $channel->queue_declare('autoflow.mercure.retry.5s', false, true, false, false, false, new AMQPTable([
        'x-message-ttl' => 5000,
        'x-dead-letter-exchange' => '',
        'x-dead-letter-routing-key' => 'autoflow.mercure.bridge',
    ]));
    $channel->queue_bind('autoflow.mercure.retry.5s', 'autoflow.mercure.retry', 'retry');
    $channel->queue_declare('autoflow.mercure.bridge', false, true, false, false, false, new AMQPTable([
        'x-dead-letter-exchange' => 'autoflow.mercure.dlx',
        'x-dead-letter-routing-key' => 'failed',
    ]));
    foreach ($exchanges as $exchange) {
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->queue_bind('autoflow.mercure.bridge', $exchange, '#');
    }
    $channel->confirm_select();
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
                $headerValues = [];
                try {
                    $headers = $message->get('application_headers');
                    $headerValues = $headers instanceof AMQPTable ? $headers->getNativeData() : [];
                    $event = MercureEvent::fromDelivery(
                        $message->getBody(),
                        $headerValues,
                        $message->get('type'),
                        $message->get('message_id'),
                        $topicBase,
                    );
                    publishMercure($hubUrl, $publisherKey, $event['topic'], $event['data']);
                    $message->ack();
                } catch (UnexpectedValueException|JsonException $exception) {
                    fwrite(STDERR, 'Rejected event: ' . $exception->getMessage() . "\n");
                    $message->reject(false);
                } catch (Throwable $exception) {
                    $retryCount = (int) ($headerValues['mercureRetryCount'] ?? 0);
                    if ($retryCount >= 12) {
                        fwrite(
                            STDERR,
                            'Mercure delivery moved to dead-letter queue after retries: '
                            . $exception->getMessage() . "\n",
                        );
                        $message->reject(false);
                        return;
                    }

                    try {
                        $properties = $message->get_properties();
                        $properties['delivery_mode'] = AMQPMessage::DELIVERY_MODE_PERSISTENT;
                        $properties['application_headers'] = new AMQPTable(array_merge($headerValues, [
                            'mercureRetryCount' => $retryCount + 1,
                        ]));
                        $retryMessage = new AMQPMessage($message->getBody(), $properties);
                        $channel->basic_publish($retryMessage, 'autoflow.mercure.retry', 'retry', true);
                        $channel->wait_for_pending_acks_returns(5);
                        $message->ack();
                        fwrite(
                            STDERR,
                            'Mercure delivery scheduled for retry ' . ($retryCount + 1) . ': '
                            . $exception->getMessage() . "\n",
                        );
                    } catch (Throwable $retryFailure) {
                        fwrite(STDERR, 'Mercure retry scheduling failed: ' . $retryFailure->getMessage() . "\n");
                        $message->nack(false, true);
                    }
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
