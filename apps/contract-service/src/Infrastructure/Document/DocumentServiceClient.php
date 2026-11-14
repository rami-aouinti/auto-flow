<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use RuntimeException;

final readonly class DocumentServiceClient
{
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
    ) {
    }

    /** @return array{id:string,checksum:string,byteSize:int} */
    public function uploadPdf(string $pdf, string $filename, string $title, string $entityType, string $entityId, string $token): array
    {
        if ($token === '') {
            throw new RuntimeException('A caller access token is required for Document Service.');
        }
        $boundary = 'autoflow-' . bin2hex(random_bytes(16));
        $fields = [
            'title' => $title,
            'category' => $entityType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ];
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($filename)) ?: 'document.pdf';
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . $safeName . "\"\r\nContent-Type: application/pdf\r\n\r\n" . $pdf . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/api/v1/documents', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'Accept' => 'application/json',
            ],
            'body' => $body,
            'timeout' => 30,
            'max_duration' => 45,
        ]);
        $status = $response->getStatusCode();
        $data = $response->toArray(false);
        $document = $data['data'] ?? null;
        if ($status !== 201 || !is_array($document) || !is_string($document['id'] ?? null)
            || !is_string($document['checksum_sha256'] ?? null)) {
            throw new RuntimeException('Document Service rejected the PDF upload (HTTP ' . $status . ').');
        }

        return [
            'id' => $document['id'],
            'checksum' => $document['checksum_sha256'],
            'byteSize' => (int) ($document['byte_size'] ?? strlen($pdf)),
        ];
    }

    public function delete(string $documentId, string $token): void
    {
        if ($token === '') {
            throw new RuntimeException('A caller access token is required for Document Service.');
        }
        $response = $this->http->request('DELETE', rtrim($this->baseUrl, '/') . '/api/v1/documents/' . rawurlencode($documentId), [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 15,
        ]);
        if (!in_array($response->getStatusCode(), [200, 204], true)) {
            throw new RuntimeException('Document Service compensation failed (HTTP ' . $response->getStatusCode() . ').');
        }
    }

    public function download(string $documentId, string $token): string
    {
        if ($token === '') {
            throw new RuntimeException('A caller access token is required for Document Service.');
        }
        $response = $this->http->request('GET', rtrim($this->baseUrl, '/') . '/api/v1/documents/' . rawurlencode($documentId) . '/content', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/pdf'],
            'timeout' => 30,
            'max_duration' => 45,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Document Service download failed (HTTP ' . $response->getStatusCode() . ').');
        }

        return $response->getContent();
    }
}
