<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Infrastructure\Identity;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class KeycloakUserDirectory
{
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private string $realm,
        private string $clientId,
        private string $clientSecret,
    ) {
    }

    public function emailForSubject(string $subject): string
    {
        $tokenResponse = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/realms/' . rawurlencode($this->realm) . '/protocol/openid-connect/token', [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
            'timeout' => 8,
        ])->toArray();

        $token = $tokenResponse['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Keycloak did not issue a notification service access token.');
        }

        $user = $this->http->request(
            'GET',
            rtrim($this->baseUrl, '/') . '/admin/realms/' . rawurlencode($this->realm) . '/users/' . rawurlencode($subject),
            ['auth_bearer' => $token, 'timeout' => 8],
        )->toArray();

        $email = $user['email'] ?? null;
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($user['enabled'] ?? false) !== true) {
            throw new RuntimeException('The offer owner has no valid enabled email address in Keycloak.');
        }

        return $email;
    }
}
