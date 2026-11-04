<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class KeycloakTokenExchangeProvider
{
    private const string GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';
    private const string ACCESS_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $tokenUrl,
        private string $clientId,
        private string $clientSecret,
        private string $pricingAudience,
    ) {
    }

    public function exchangeForPricing(string $subjectToken): string
    {
        if (
            trim($subjectToken) !== $subjectToken
            || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $subjectToken) !== 1
        ) {
            throw new RuntimeException('A verified user access token is required for token exchange.');
        }

        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'auth_basic' => [$this->clientId, $this->clientSecret],
                'body' => [
                    'grant_type' => self::GRANT_TYPE,
                    'subject_token' => $subjectToken,
                    'subject_token_type' => self::ACCESS_TOKEN_TYPE,
                    'requested_token_type' => self::ACCESS_TOKEN_TYPE,
                    'audience' => $this->pricingAudience,
                ],
                'headers' => ['Accept' => 'application/json'],
                'max_redirects' => 0,
                'timeout' => 3.0,
            ]);

            $payload = $response->toArray();
            $accessToken = $payload['access_token'] ?? null;
            $tokenType = $payload['token_type'] ?? null;
            $expiresIn = $payload['expires_in'] ?? null;

            if (
                !is_string($accessToken)
                || $accessToken === ''
                || $tokenType !== 'Bearer'
                || !is_numeric($expiresIn)
                || (int) $expiresIn < 1
            ) {
                throw new RuntimeException('The identity provider returned an invalid exchanged-token response.');
            }

            return $accessToken;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Could not exchange the authenticated user token for the Pricing Service.',
                0,
                $exception,
            );
        }
    }
}
