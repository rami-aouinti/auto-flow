<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class KeycloakClientCredentialsTokenProvider
{
    private ?string $accessToken = null;

    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->expiresAt) {
            return $this->accessToken;
        }

        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 3.0,
            ]);

            $payload = $response->toArray();
            $token = $payload['access_token'] ?? null;
            $expiresIn = $payload['expires_in'] ?? null;

            if (!is_string($token) || $token === '' || !is_numeric($expiresIn)) {
                throw new RuntimeException('The identity provider returned an invalid access token response.');
            }

            $this->accessToken = $token;
            $this->expiresAt = time() + max(1, (int) $expiresIn - 30);

            return $this->accessToken;
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not obtain a service access token for the Pricing Service.', 0, $exception);
        }
    }
}
