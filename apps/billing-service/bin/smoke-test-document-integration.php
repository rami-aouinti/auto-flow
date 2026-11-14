<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

$token = getenv('DOCUMENT_E2E_TOKEN') ?: '';
$invoiceId = getenv('BILLING_E2E_INVOICE_ID') ?: '';
$base = rtrim(getenv('AUTOFLOW_GATEWAY_URL') ?: 'http://api-gateway', '/');
if ($token === '' || !Uuid::isValid($invoiceId)) {
    fwrite(STDERR, "Set DOCUMENT_E2E_TOKEN and BILLING_E2E_INVOICE_ID (a legally complete issued invoice UUID).\n");
    exit(2);
}
$http = HttpClient::create(['timeout' => 35, 'max_duration' => 50]);
$headers = ['Authorization' => 'Bearer ' . $token];
$pdf1 = $http->request('GET', $base . '/api/v1/billing/invoices/' . $invoiceId . '/document', ['headers' => $headers])->getContent();
$pdf2 = $http->request('GET', $base . '/api/v1/billing/invoices/' . $invoiceId . '/document', ['headers' => $headers])->getContent();
if (!str_starts_with($pdf1, '%PDF-') || !hash_equals(hash('sha256', $pdf1), hash('sha256', $pdf2))) {
    throw new RuntimeException('Billing PDF download/replay is not stable.');
}
$documents = $http->request('GET', $base . '/api/v1/documents?entity_type=invoice&entity_id=' . rawurlencode($invoiceId), ['headers' => $headers])->toArray();
$matches = array_values(array_filter($documents['data'] ?? [], static fn (array $d): bool => ($d['entity_type'] ?? null) === 'invoice' && ($d['entity_id'] ?? null) === $invoiceId));
if (count($matches) !== 1 || !hash_equals((string) ($matches[0]['checksum_sha256'] ?? ''), hash('sha256', $pdf1))) {
    throw new RuntimeException('Invoice PDF was not stored exactly once in Document Service or its checksum differs.');
}
fwrite(STDOUT, "Billing -> Document Service E2E passed (lazy upload, idempotent retrieval, canonical download, SHA-256).\n");
