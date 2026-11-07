<?php

declare(strict_types=1);

namespace App\\Infrastructure\\Signature;

use App\\Application\\Port\\SignatureProviderInterface;
use Firebase\\JWT\\JWT;
use Symfony\\Component\\Cache\\CacheInterface;
use Symfony\\Component\\HttpClient\\HttpClientInterface;
use Symfony\\Contracts\\Cache\\ItemInterface;

final readonly class DocuSignSignatureProvider implements SignatureProviderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private string $integrationKey,
        private string $userId,
        private string $accountId,
        private string $oauthHost,
        private string $apiBaseUrl,
        private string $privateKeyPath,
    ) {
    }

    public function send(
        string $pdfPath,
        string $recipientEmail,
        string $recipientName,
        string $idempotencyKey,
    ): array {
        $this->assertConfigured();
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new \\RuntimeException('The prepared contract PDF is not readable.');
        }
        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \\DomainException('The customer snapshot does not contain a valid signing email address.');
        }

        $token = $this->accessToken();
        $pdf = file_get_contents($pdfPath);
        if (!is_string($pdf) || $pdf === '') {
            throw new \\RuntimeException('The prepared contract PDF could not be read.');
        }

        $response = $this->http->request(
            'POST',
            'https://' . $this->oauthHost . '/oauth/token',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-DocuSign-Idempotency-Key' => $idempotencyKey,
                ],
                'json' => [
                    'emailSubject' => 'Please sign your AutoFlow vehicle contract',
                    'documents' => [[
                        'documentBase64' => base64_encode($pdf),
                        'name' => 'AutoFlow vehicle contract',
                        'fileExtension' => 'pdf',
                        'documentId' => '1',
                    ]],
                    'recipients' => [
                        'signers' => [[
                            'email' => $recipientEmail,
                            'name' => $recipientName !== '' ? $recipientName : $recipientEmail,
                            'recipientId' => '1',
                            'routingOrder' => '1',
                        ]],
                    ],
                    'status' => 'sent',
                ],
                'timeout' => 20,
                'max_redirects' => 0,
            ],
        );

        $payload = $response->toArray(false);
        $envelopeId = $payload['envelopeId'] ?? null;
        $status = $payload['status'] ?? null;
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300
            || !is_string($envelopeId) || $envelopeId === ''
            || !is_string($status) || strtolower($status) !== 'sent') {
            throw new \\RuntimeException('DocuSign did not confirm creation of the signature envelope.');
        }

        return [
            'provider' => 'docusign',
            'envelopeId' => $envelopeId,
            'status' => strtolower($status),
        ];
    }

    private function accessToken(): string
    {
        $cacheKey = 'autoflow.docusign.jwt.' . hash('sha256', $this->integrationKey . ':' . $this->userId);

        return $this->cache->get($cacheKey, function (ItemInterface $item): string {
            $privateKey = file_get_contents($this->privateKeyPath);
            if (!is_string($privateKey) || $privateKey === '') {
                throw new \\RuntimeException('DocuSign RSA private key could not be loaded.');
            }

            $now = time();
            $assertion = JWT::encode([
                'iss' => $this->integrationKey,
                'sub' => $this->userId,
                'aud' => $this->oauthHost,
                'iat' => $now,
                'exp' => $now + 3600,
                'scope' => 'signature impersonation',
            ], $privateKey, 'RS256');

            $response = $this->http->request(
                'POST',
                'https://' . $this->oauthHost . '/oauth/token',
                [
                    'headers' => ['Accept' => 'application/json'],
                    'body' => [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $assertion,
                    ],
                    'timeout' => 10,
                    'max_redirects' => 0,
                ],
            );
            $payload = $response->toArray(false);
            $token = $payload['access_token'] ?? null;
            $expiresIn = $payload['expires_in'] ?? 3600;
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300
                || !is_string($token) || $token === '') {
                throw new \\RuntimeException('DocuSign OAuth JWT grant failed. Check consent, account and integration key.');
            }

            $item->expiresAfter(max(60, min(3300, (int) $expiresIn - 60)));

            return $token;
        });
    }

    private function assertConfigured(): void
    {
        foreach ([
            $this->integrationKey,
            $this->userId,
            $this->accountId,
            $this->oauthHost,
            $this->apiBaseUrl,
            $this->privateKeyPath,
        ] as $value) {
            if (trim($value) === '') {
                throw new \\RuntimeException('DocuSign is not configured. Set the DocuSign integration key, user, account, endpoints and RSA key path.');
            }
        }
    }
}
