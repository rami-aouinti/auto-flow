<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$token = getenv('DOCUMENT_E2E_TOKEN') ?: '';
$contractId = getenv('CONTRACT_E2E_CONTRACT_ID') ?: '';
$base = rtrim(getenv('AUTOFLOW_GATEWAY_URL') ?: 'http://api-gateway', '/');
if ($token === '' || !Symfony\Component\Uid\Uuid::isValid($contractId)) {
    fwrite(STDERR, "Set DOCUMENT_E2E_TOKEN and CONTRACT_E2E_CONTRACT_ID (a prepared contract UUID).\n");
    exit(2);
}
$http = HttpClient::create(['timeout' => 35, 'max_duration' => 50]);
$headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
$generate = $http->request('POST', $base . '/api/v1/contracts/' . $contractId . '/document', ['headers' => $headers]);
if ($generate->getStatusCode() !== 200) {
    throw new RuntimeException('Contract PDF generation failed: HTTP ' . $generate->getStatusCode() . ' ' . $generate->getContent(false));
}
$payload = $generate->toArray();
$documentId = $payload['document']['documentId'] ?? '';
if (!is_string($documentId) || !Symfony\Component\Uid\Uuid::isValid($documentId)) {
    throw new RuntimeException('Contract response did not return a Document Service UUID.');
}
$replay = $http->request('POST', $base . '/api/v1/contracts/' . $contractId . '/document', ['headers' => $headers])->toArray();
if (($replay['document']['documentId'] ?? null) !== $documentId) {
    throw new RuntimeException('Repeated contract PDF generation created a duplicate Document Service object.');
}
$pdf = $http->request('GET', $base . '/api/v1/contracts/' . $contractId . '/document', ['headers' => ['Authorization' => 'Bearer ' . $token]])->getContent();
if (!str_starts_with($pdf, '%PDF-')) {
    throw new RuntimeException('Contract download is not a PDF.');
}
$documents = $http->request('GET', $base . '/api/v1/documents?entity_type=contract&entity_id=' . rawurlencode($contractId), ['headers' => $headers])->toArray();
$matches = array_values(array_filter($documents['data'] ?? [], static fn (array $d): bool => ($d['id'] ?? null) === $documentId));
if (count($matches) !== 1 || !hash_equals((string) ($matches[0]['checksum_sha256'] ?? ''), hash('sha256', $pdf))) {
    throw new RuntimeException('Contract document metadata/checksum does not match the canonical Document Service object.');
}
fwrite(STDOUT, "Contract -> Document Service E2E passed (upload, idempotent replay, canonical download, SHA-256).\n");
