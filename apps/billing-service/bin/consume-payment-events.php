<?php

declare(strict_types=1);
use App\Application\PaymentEventEnvelope;
use App\Application\InvoiceLegalSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__).'/vendor/autoload.php';
$url = getenv('BILLING_DATABASE_URL') ?: '';
if ($url === '') {
    throw new RuntimeException('BILLING_DATABASE_URL is required.');
}$p = parse_url($url);
if (!is_array($p) || !in_array($p['scheme'] ?? '', ['postgres','postgresql'], true)) {
    throw new RuntimeException('Invalid PostgreSQL URL.');
}
$db = DriverManager::getConnection(['driver' => 'pdo_pgsql','host' => $p['host'] ?? 'localhost','port' => $p['port'] ?? 5432,'user' => isset($p['user']) ? rawurldecode($p['user']) : '','password' => isset($p['pass']) ? rawurldecode($p['pass']) : '','dbname' => rawurldecode(ltrim($p['path'] ?? '', '/'))]);
$c = new AMQPStreamConnection(getenv('RABBITMQ_HOST') ?: 'rabbitmq', (int)(getenv('RABBITMQ_PORT') ?: 5672), getenv('RABBITMQ_USER') ?: 'autoflow', getenv('RABBITMQ_PASSWORD') ?: 'autoflow', getenv('RABBITMQ_VHOST') ?: '/', false, 'PLAIN', null, 'en_US', 3.0, 3.0, null, true, 30);
$ch = $c->channel();
$ex = 'autoflow.payment.events';
$q = 'autoflow.billing.payment-events';
$ch->exchange_declare($ex, 'topic', false, true, false);
$ch->queue_declare($q, false, true, false, false);
foreach (['PaymentCaptured','PaymentRefunded','PaymentPartiallyRefunded'] as $t) {
    $ch->queue_bind($q, $ex, $t);
}$ch->basic_qos(0, 10, false);
$rate = max(0, min(10000, (int)(getenv('BILLING_TAX_RATE_BPS') ?: 1900)));
$issuerAddress = json_decode(getenv('BILLING_ISSUER_ADDRESS_JSON') ?: '{}', true);
$issuer = [
    'legalName' => getenv('BILLING_ISSUER_LEGAL_NAME') ?: '',
    'taxNumber' => getenv('BILLING_ISSUER_TAX_NUMBER') ?: '',
    'vatId' => getenv('BILLING_ISSUER_VAT_ID') ?: '',
    'address' => is_array($issuerAddress) ? $issuerAddress : [],
];
$tax = static function (int $gross, int $bps): array {
    $v = (int)round($gross * $bps / (10000 + $bps), 0, PHP_ROUND_HALF_UP);
    return ['net' => $gross - $v,'tax' => $v];
};
$number = static function (Connection $db, string $table, string $tenant, int $year, string $prefix): string {
    if (!in_array($table, ['billing_invoice_sequences','billing_credit_note_sequences'], true)) {
        throw new InvalidArgumentException();
    }$db->executeStatement("INSERT INTO {$table}(tenant_id,fiscal_year,last_number) VALUES(:t,:y,1) ON CONFLICT(tenant_id,fiscal_year) DO UPDATE SET last_number={$table}.last_number+1", ['t' => $tenant,'y' => $year]);
    $n = (int)$db->fetchOne("SELECT last_number FROM {$table} WHERE tenant_id=:t AND fiscal_year=:y", ['t' => $tenant,'y' => $year]);
    return sprintf('%s-%d-%06d', $prefix, $year, $n);
};
$process = static function (AMQPMessage $m) use ($db, $rate, $tax, $number, $issuer): void {
    $event = PaymentEventEnvelope::fromMessage(
        $m->getBody(),
        (string) ($m->get('message_id') ?? ''),
        (string) ($m->get('type') ?? ''),
    );
    $eid = $event->eventId;
    $type = $event->eventType;
    $tenant = $event->tenantId;
    $d = $event->data;
    $pid = $event->paymentId;
    $oid = $event->orderId;
    $payloadHash = $event->payloadHash;
    $db->transactional(static function (Connection $db) use ($eid, $type, $tenant, $d, $pid, $oid, $payloadHash, $rate, $tax, $number, $issuer): void {
        $db->executeQuery("SELECT set_config('app.tenant_id',:t,true)", ['t' => $tenant]);
        $insert = $db->fetchOne(
            'INSERT INTO billing_event_inbox(tenant_id,event_id,event_type,payload_hash) VALUES(:t,:e,:y,:hash) ON CONFLICT DO NOTHING RETURNING event_id',
            ['t' => $tenant, 'e' => $eid, 'y' => $type, 'hash' => $payloadHash],
        );
        if ($insert === false) {
            $existingHash = $db->fetchOne(
                'SELECT payload_hash FROM billing_event_inbox WHERE tenant_id=:t AND event_id=:e',
                ['t' => $tenant, 'e' => $eid],
            );
            if (is_string($existingHash) && hash_equals(str_repeat('0', 64), trim($existingHash))) {
                // Rows created before payload fingerprints were introduced are upgraded
                // on their first redelivery; subsequent replays are checked strictly.
                $db->executeStatement(
                    "UPDATE billing_event_inbox SET payload_hash=:hash WHERE tenant_id=:t AND event_id=:e AND payload_hash=repeat('0',64)",
                    ['hash' => $payloadHash, 't' => $tenant, 'e' => $eid],
                );

                return;
            }
            if (!is_string($existingHash) || !hash_equals(trim($existingHash), $payloadHash)) {
                throw new UnexpectedValueException('A payment eventId was reused with a different payload.');
            }

            return;
        }
        if ($type === 'PaymentCaptured') {
            $gross = filter_var($d['amountMinor'] ?? null, FILTER_VALIDATE_INT);
            $currency = $d['currency'] ?? null;
            if (!is_int($gross) || $gross < 1 || !is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new UnexpectedValueException('Captured payment requires amountMinor and ISO currency.');
            }if ($db->fetchOne('SELECT invoice_id FROM billing_invoices WHERE tenant_id=:t AND payment_id=:p', ['t' => $tenant,'p' => $pid]) !== false) {
                return;
            }$a = $tax($gross, $rate);
            $year = (int)gmdate('Y');
            $id = Uuid::v7()->toRfc4122();
            $docNo = $number($db, 'billing_invoice_sequences', $tenant, $year, 'AF');
            $customerSnapshot = is_array($d['billingSnapshot'] ?? null) && !array_is_list($d['billingSnapshot'])
                ? $d['billingSnapshot']
                : [];
            $legalSnapshot = InvoiceLegalSnapshot::build($customerSnapshot, $issuer);
            $snap = [
                'orderId' => $oid, 'paymentId' => $pid, 'currency' => $currency,
                'grossMinor' => $gross, 'netMinor' => $a['net'], 'taxMinor' => $a['tax'],
                'taxRateBasisPoints' => $rate, 'sourceEventId' => $eid,
                ...$legalSnapshot,
            ];
            $db->insert('billing_invoices', [
                'tenant_id' => $tenant, 'invoice_id' => $id, 'invoice_number' => $docNo,
                'payment_id' => $pid, 'order_id' => $oid, 'currency' => $currency,
                'gross_minor' => $gross, 'net_minor' => $a['net'], 'tax_minor' => $a['tax'],
                'tax_rate_basis_points' => $rate, 'status' => $legalSnapshot['documentStatus'],
                'billing_snapshot' => json_encode($snap, JSON_THROW_ON_ERROR),
                'source_event_id' => $eid,
                'paid_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ]);
            return;
        }
        if (in_array($type, ['PaymentRefunded','PaymentPartiallyRefunded'], true)) {
            $refund = filter_var($d['refundAmountMinor'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($refund) || $refund < 1) {
                throw new UnexpectedValueException('Refund event requires refundAmountMinor.');
            }$invoice = $db->fetchAssociative('SELECT invoice_id,currency,tax_rate_basis_points,billing_snapshot FROM billing_invoices WHERE tenant_id=:t AND payment_id=:p FOR UPDATE', ['t' => $tenant,'p' => $pid]);
            if ($invoice === false) {
                throw new RuntimeException('Source invoice missing; retry event.');
            }$currency = $d['currency'] ?? $invoice['currency'];
            if ($currency !== $invoice['currency']) {
                throw new UnexpectedValueException('Refund currency mismatch.');
            }$bps = (int)$invoice['tax_rate_basis_points'];
            $a = $tax($refund, $bps);
            $year = (int)gmdate('Y');
            $id = Uuid::v7()->toRfc4122();
            $docNo = $number($db, 'billing_credit_note_sequences', $tenant, $year, 'AC');
            $db->insert('billing_credit_notes', ['tenant_id' => $tenant,'credit_note_id' => $id,'credit_note_number' => $docNo,'invoice_id' => $invoice['invoice_id'],'payment_id' => $pid,'order_id' => $oid,'currency' => $currency,'gross_minor' => $refund,'net_minor' => $a['net'],'tax_minor' => $a['tax'],'tax_rate_basis_points' => $bps,'reason' => $type === 'PaymentRefunded' ? 'Payment refunded' : 'Partial payment refund','source_event_id' => $eid,'billing_snapshot' => $invoice['billing_snapshot']]);
        }
    });
};
$deadExchange = 'autoflow.billing.payment-events.dlx';
$deadQueue = 'autoflow.billing.payment-events.dead';
$ch->exchange_declare($deadExchange, 'direct', false, true, false);
$ch->queue_declare($deadQueue, false, true, false, false);
$ch->queue_bind($deadQueue, $deadExchange, 'invalid');
$ch->confirm_select();

fwrite(STDOUT, "Billing Payment event consumer started.\n");
$ch->basic_consume($q, '', false, false, false, false, static function (AMQPMessage $m) use ($process, $ch, $deadExchange): void {
    try {
        $process($m);
        $m->ack();
    } catch (UnexpectedValueException|JsonException|InvalidArgumentException $e) {
        try {
            $dead = new AMQPMessage($m->getBody(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => (string) ($m->get('message_id') ?? ''),
                'type' => (string) ($m->get('type') ?? 'invalid'),
                'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                    'rejectionReason' => mb_substr($e->getMessage(), 0, 240),
                    'originalQueue' => 'autoflow.billing.payment-events',
                ]),
            ]);
            $ch->basic_publish($dead, $deadExchange, 'invalid', true);
            $ch->wait_for_pending_acks_returns(5);
            $m->ack();
        } catch (Throwable $publishError) {
            fwrite(STDERR, 'Billing DLQ publish failed; requeueing: '.$publishError->getMessage().PHP_EOL);
            $m->nack(false, true);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Billing event failed; requeueing: '.$e->getMessage().PHP_EOL);
        $m->nack(false, true);
    }
});
try {
    while ($ch->is_consuming()) {
        $ch->wait();
    }
} finally {
    $ch->close();
    $c->close();
}
