<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseUrl = getenv('BILLING_DATABASE_URL') ?: '';
if ($databaseUrl === '') {
    throw new RuntimeException('BILLING_DATABASE_URL is required.');
}
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
);
$channel = $connection->channel();
$exchange = 'autoflow.payment.events';
$deadQueue = 'autoflow.billing.payment-events.dead';
$channel->exchange_declare($exchange, 'topic', false, true, false);
$channel->queue_declare($deadQueue, false, true, false, false);
$channel->confirm_select();

$tenant = 'billing-e2e-' . bin2hex(random_bytes(6));
$paymentId = Uuid::v7()->toRfc4122();
$orderId = Uuid::v7()->toRfc4122();
$capturedEventId = Uuid::v7()->toRfc4122();
$refundEventId = Uuid::v7()->toRfc4122();
$occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
$billingSnapshot = [
    'customerId' => Uuid::v7()->toRfc4122(),
    'customerType' => 'company',
    'legalName' => 'Example GmbH',
    'firstName' => null,
    'lastName' => null,
    'email' => 'billing@example.test',
    'vatId' => 'DE123456789',
    'billingAddress' => [
        'line1' => 'Customer Street 1',
        'line2' => null,
        'postalCode' => '50667',
        'city' => 'Köln',
        'region' => null,
        'countryCode' => 'DE',
    ],
    'customerVersion' => 1,
];

$publish = static function (string $eventId, string $eventType, array $data) use ($channel, $exchange, $tenant, $paymentId, $occurredAt): void {
    $envelope = [
        'eventId' => $eventId,
        'eventType' => $eventType,
        'schemaVersion' => 1,
        'producer' => 'payment-service',
        'aggregate' => ['type' => 'Payment', 'id' => $paymentId],
        'tenantId' => $tenant,
        'occurredAt' => $occurredAt,
        'data' => $data,
    ];
    $channel->basic_publish(new AMQPMessage(json_encode($envelope, JSON_THROW_ON_ERROR), [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'message_id' => $eventId,
        'type' => $eventType,
    ]), $exchange, $eventType, true);
    $channel->wait_for_pending_acks_returns(5);
};

$db->executeQuery("SELECT set_config('app.tenant_id', :tenant, false)", ['tenant' => $tenant]);
$waitFor = static function (callable $query, string $description) use ($db): array {
    $deadline = microtime(true) + 25;
    do {
        $row = $query();
        if (is_array($row)) {
            return $row;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for ' . $description . '.');
};

try {
    $capturedData = [
        'paymentId' => $paymentId,
        'orderId' => $orderId,
        'amountMinor' => 11900,
        'currency' => 'EUR',
        'status' => 'captured',
        'billingSnapshot' => $billingSnapshot,
    ];
    $publish($capturedEventId, 'PaymentCaptured', $capturedData);
    $invoice = $waitFor(
        static fn (): array|false => $db->fetchAssociative(
            'SELECT invoice_id,status,billing_snapshot FROM billing_invoices WHERE tenant_id=:tenant AND payment_id=:payment',
            ['tenant' => $tenant, 'payment' => $paymentId],
        ),
        'the invoice generated from PaymentCaptured',
    );
    if (!is_array($invoice)) {
        throw new RuntimeException('Billing did not create an invoice.');
    }
    $snapshot = is_array($invoice['billing_snapshot'])
        ? $invoice['billing_snapshot']
        : json_decode((string) $invoice['billing_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    if (($snapshot['recipient']['displayName'] ?? null) !== 'Example GmbH'
        || ($snapshot['recipient']['address']['postalCode'] ?? null) !== '50667'
        || ($snapshot['paymentId'] ?? null) !== $paymentId
    ) {
        throw new RuntimeException('Invoice did not preserve the immutable customer/payment legal snapshot.');
    }

    // Exact event redelivery must not create a second invoice.
    $publish($capturedEventId, 'PaymentCaptured', $capturedData);
    usleep(500000);
    $invoiceCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM billing_invoices WHERE tenant_id=:tenant AND payment_id=:payment',
        ['tenant' => $tenant, 'payment' => $paymentId],
    );
    if ($invoiceCount !== 1) {
        throw new RuntimeException('PaymentCaptured redelivery created duplicate invoices.');
    }

    $refundData = [
        'paymentId' => $paymentId,
        'orderId' => $orderId,
        'status' => 'partially_refunded',
        'refundAmountMinor' => 1190,
        'currency' => 'EUR',
        'billingSnapshot' => $billingSnapshot,
    ];
    $publish($refundEventId, 'PaymentPartiallyRefunded', $refundData);
    $creditNote = $waitFor(
        static fn (): array|false => $db->fetchAssociative(
            'SELECT credit_note_id,billing_snapshot FROM billing_credit_notes WHERE tenant_id=:tenant AND payment_id=:payment',
            ['tenant' => $tenant, 'payment' => $paymentId],
        ),
        'the credit note generated from PaymentPartiallyRefunded',
    );
    if (!is_array($creditNote)) {
        throw new RuntimeException('Billing did not create a credit note.');
    }
    $creditSnapshot = is_array($creditNote['billing_snapshot'])
        ? $creditNote['billing_snapshot']
        : json_decode((string) $creditNote['billing_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    if (($creditSnapshot['recipient']['displayName'] ?? null) !== 'Example GmbH') {
        throw new RuntimeException('Credit note did not preserve the source invoice legal snapshot.');
    }

    // Reusing an eventId with changed content must be dead-lettered, not silently ignored.
    $collision = $refundData;
    $collision['refundAmountMinor'] = 1290;
    $publish($refundEventId, 'PaymentPartiallyRefunded', $collision);
    $deadline = microtime(true) + 20;
    $deadLetter = null;
    do {
        $deadLetter = $channel->basic_get($deadQueue, false);
        if ($deadLetter instanceof AMQPMessage) {
            break;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);
    if (!$deadLetter instanceof AMQPMessage) {
        throw new RuntimeException('Conflicting payment event was not routed to the Billing dead-letter queue.');
    }
    $deadLetter->ack();

    $creditNoteCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM billing_credit_notes WHERE tenant_id=:tenant AND payment_id=:payment',
        ['tenant' => $tenant, 'payment' => $paymentId],
    );
    $inboxCount = (int) $db->fetchOne(
        'SELECT COUNT(*) FROM billing_event_inbox WHERE tenant_id=:tenant',
        ['tenant' => $tenant],
    );
    if ($creditNoteCount !== 1 || $inboxCount !== 2) {
        throw new RuntimeException('Billing Inbox/credit note idempotency invariants were violated.');
    }

    fwrite(STDOUT, "Payment -> Billing RabbitMQ end-to-end test passed (invoice, credit note, replay and DLQ).\n");
} finally {
    if ($channel->is_open()) {
        $channel->close();
    }
    if ($connection->isConnected()) {
        $connection->close();
    }
}
