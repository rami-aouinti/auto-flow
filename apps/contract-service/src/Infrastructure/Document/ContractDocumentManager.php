<?php

declare(strict_types=1);

namespace App\\Infrastructure\\Document;

use Dompdf\\Dompdf;
use Dompdf\\Options;
use Doctrine\\DBAL\\Connection;
use Symfony\\Component\\Uid\\Uuid;
use Throwable;

final readonly class ContractDocumentManager
{
    public function __construct(
        private Connection $db,
        private string $storagePath,
    ) {
    }

    /**
     * @return array{documentId:string,contractId:string,version:int,checksum:string,byteSize:int,generatedAt:string,path:string}|null
     */
    public function generate(string $tenant, string $actor, string $contractId): ?array
    {
        $finalPath = null;

        try {
            return $this->db->transactional(function (Connection $db) use (
                $tenant,
                $actor,
                $contractId,
                &$finalPath,
            ): ?array {
                $this->setTenant($db, $tenant);
                $contract = $db->fetchAssociative(
                    'SELECT contract_id, status, contract_type, contract_version, legal_snapshot
                     FROM contract_records
                     WHERE tenant_id = :tenant AND contract_id = :id
                     FOR UPDATE',
                    ['tenant' => $tenant, 'id' => $contractId],
                );
                if (!is_array($contract)) {
                    return null;
                }
                if ($contract['status'] !== 'prepared') {
                    throw new \\DomainException('Only prepared contracts can have a PDF document generated.');
                }

                $existing = $db->fetchAssociative(
                    'SELECT document_id, document_version, storage_key, checksum_sha256, byte_size, generated_at
                     FROM contract_documents
                     WHERE tenant_id = :tenant AND contract_id = :id
                     ORDER BY document_version DESC LIMIT 1',
                    ['tenant' => $tenant, 'id' => $contractId],
                );
                if (is_array($existing)) {
                    return $this->metadata($existing, $contractId);
                }

                $snapshot = json_decode((string) $contract['legal_snapshot'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($snapshot) || $snapshot === []) {
                    throw new \\UnexpectedValueException('Contract legal snapshot is invalid.');
                }
                $pdf = $this->render($contractId, $contract, $snapshot);
                $checksum = hash('sha256', $pdf);
                $version = 1;
                $tenantDirectory = hash('sha256', $tenant);
                $storageKey = $tenantDirectory . '/' . $contractId . '/contract-v' . $version . '.pdf';
                $directory = rtrim($this->storagePath, '/') . '/' . $tenantDirectory . '/' . $contractId;
                if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                    throw new \\RuntimeException('Could not create the contract document directory.');
                }

                $finalPath = $directory . '/contract-v' . $version . '.pdf';
                $temporaryPath = $finalPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
                if (file_put_contents($temporaryPath, $pdf, LOCK_EX) !== strlen($pdf)) {
                    @unlink($temporaryPath);
                    throw new \\RuntimeException('Could not persist the generated PDF.');
                }
                if (!rename($temporaryPath, $finalPath)) {
                    @unlink($temporaryPath);
                    throw new \\RuntimeException('Could not atomically publish the generated PDF.');
                }

                $documentId = Uuid::v7()->toRfc4122();
                $generatedAt = (new \\DateTimeImmutable())->format(DATE_ATOM);
                $db->insert('contract_documents', [
                    'tenant_id' => $tenant,
                    'document_id' => $documentId,
                    'contract_id' => $contractId,
                    'document_version' => $version,
                    'storage_key' => $storageKey,
                    'mime_type' => 'application/pdf',
                    'checksum_sha256' => $checksum,
                    'byte_size' => strlen($pdf),
                    'generated_by' => $actor,
                    'generated_at' => $generatedAt,
                ]);
                $db->update('contract_records', [
                    'document_checksum' => $checksum,
                    'updated_at' => $generatedAt,
                ], ['tenant_id' => $tenant, 'contract_id' => $contractId]);

                $db->insert('contract_audit_events', [
                    'tenant_id' => $tenant,
                    'event_id' => Uuid::v7()->toRfc4122(),
                    'contract_id' => $contractId,
                    'actor_id' => $actor,
                    'event_type' => 'ContractPdfGenerated',
                    'payload' => json_encode([
                        'documentId' => $documentId,
                        'version' => $version,
                        'checksumSha256' => $checksum,
                        'byteSize' => strlen($pdf),
                    ], JSON_THROW_ON_ERROR),
                ]);
                $db->insert('contract_outbox', [
                    'tenant_id' => $tenant,
                    'event_id' => Uuid::v7()->toRfc4122(),
                    'contract_id' => $contractId,
                    'event_type' => 'contract.document.generated.v1',
                    'payload' => json_encode([
                        'contractId' => $contractId,
                        'documentId' => $documentId,
                        'version' => $version,
                        'checksumSha256' => $checksum,
                    ], JSON_THROW_ON_ERROR),
                ]);

                return [
                    'documentId' => $documentId,
                    'contractId' => $contractId,
                    'version' => $version,
                    'checksum' => $checksum,
                    'byteSize' => strlen($pdf),
                    'generatedAt' => $generatedAt,
                    'path' => $finalPath,
                ];
            });
        } catch (Throwable $exception) {
            if (is_string($finalPath) && is_file($finalPath)) {
                @unlink($finalPath);
            }

            throw $exception;
        }
    }

    /**
     * @return array{documentId:string,contractId:string,version:int,checksum:string,byteSize:int,generatedAt:string,path:string}|null
     */
    public function findLatest(string $tenant, string $contractId): ?array
    {
        return $this->db->transactional(function (Connection $db) use ($tenant, $contractId): ?array {
            $this->setTenant($db, $tenant);
            $row = $db->fetchAssociative(
                'SELECT document_id, document_version, storage_key, checksum_sha256, byte_size, generated_at
                 FROM contract_documents
                 WHERE tenant_id = :tenant AND contract_id = :id
                 ORDER BY document_version DESC LIMIT 1',
                ['tenant' => $tenant, 'id' => $contractId],
            );
            if (!is_array($row)) {
                return null;
            }

            return $this->metadata($row, $contractId);
        });
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $snapshot
     */
    private function render(string $contractId, array $contract, array $snapshot): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(
            is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $customer = is_array($snapshot['customer'] ?? null) ? $snapshot['customer'] : [];
        $vehicle = is_array($snapshot['vehicle'] ?? null) ? $snapshot['vehicle'] : [];
        $quote = is_array($snapshot['quote'] ?? null) ? $snapshot['quote'] : [];
        $offer = is_array($snapshot['offer'] ?? null) ? $snapshot['offer'] : [];
        $capturedAt = $escape($snapshot['capturedAt'] ?? '');
        $customerName = $customer['displayName'] ?? $customer['name'] ?? $customer['legalName'] ?? 'Not provided';
        $vehicleName = $vehicle['displayName'] ?? $vehicle['name'] ?? $vehicle['model'] ?? 'Vehicle details in snapshot';
        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#172033;line-height:1.5}
            h1{font-size:22px;color:#14213d;margin-bottom:4px}h2{font-size:14px;margin-top:24px;border-bottom:1px solid #cbd5e1;padding-bottom:5px}
            .muted{color:#64748b}.notice{border:1px solid #d97706;background:#fffbeb;padding:10px;margin:16px 0}
            table{width:100%;border-collapse:collapse}td{padding:6px;border-bottom:1px solid #e2e8f0;vertical-align:top}
            td:first-child{width:28%;font-weight:bold;color:#475569}.snapshot{white-space:pre-wrap;word-wrap:break-word;font-size:8px}
            </style></head><body>';
        $html .= '<h1>AutoFlow Vehicle Contract</h1><p class="muted">Contract ' . $escape($contractId) . '</p>';
        $html .= '<div class="notice"><strong>Review document.</strong> This generated draft is not legal advice and is not an executed agreement. It becomes binding only after the required parties sign the final approved document.</div>';
        $html .= '<h2>Contract details</h2><table>';
        $html .= '<tr><td>Contract type</td><td>' . $escape($contract['contract_type']) . '</td></tr>';
        $html .= '<tr><td>Contract version</td><td>' . $escape($contract['contract_version']) . '</td></tr>';
        $html .= '<tr><td>Order ID</td><td>' . $escape($snapshot['orderId'] ?? '') . '</td></tr>';
        $html .= '<tr><td>Snapshot captured</td><td>' . $capturedAt . '</td></tr>';
        $html .= '<tr><td>Customer</td><td>' . $escape($customerName) . '</td></tr>';
        $html .= '<tr><td>Customer email</td><td>' . $escape($customer['email'] ?? 'Not provided') . '</td></tr>';
        $html .= '<tr><td>Vehicle</td><td>' . $escape($vehicleName) . '</td></tr>';
        $html .= '</table>';
        foreach (['offer' => $offer, 'quote' => $quote, 'vehicle' => $vehicle] as $section => $data) {
            $html .= '<h2>' . $escape(ucfirst($section)) . ' snapshot</h2><div class="snapshot">'
                . $escape(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                . '</div>';
        }
        $html .= '</body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{documentId:string,contractId:string,version:int,checksum:string,byteSize:int,generatedAt:string,path:string}
     */
    private function metadata(array $row, string $contractId): array
    {
        $key = (string) $row['storage_key'];
        if (preg_match('~^[a-f0-9]{64}/[0-9a-f-]{36}/contract-v[1-9][0-9]*\\.pdf$~D', $key) !== 1) {
            throw new \\UnexpectedValueException('Stored contract document key is invalid.');
        }
        $root = realpath($this->storagePath);
        $path = rtrim($this->storagePath, '/') . '/' . $key;
        $realPath = realpath($path);
        if ($root === false || $realPath === false || !str_starts_with($realPath, $root . DIRECTORY_SEPARATOR)) {
            throw new \\RuntimeException('Stored contract PDF is missing.');
        }
        $checksum = hash_file('sha256', $realPath);
        if (!is_string($checksum) || !hash_equals((string) $row['checksum_sha256'], $checksum)) {
            throw new \\RuntimeException('Stored contract PDF failed its SHA-256 integrity check.');
        }

        return [
            'documentId' => (string) $row['document_id'],
            'contractId' => $contractId,
            'version' => (int) $row['document_version'],
            'checksum' => $checksum,
            'byteSize' => (int) $row['byte_size'],
            'generatedAt' => (string) $row['generated_at'],
            'path' => $realPath,
        ];
    }

    private function setTenant(Connection $db, string $tenant): void
    {
        $db->executeQuery("SELECT set_config('app.tenant_id', :tenant, true)", ['tenant' => $tenant]);
    }
}
