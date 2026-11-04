<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use Firebase\JWT\JWT;
use Throwable;

final readonly class JwtTokenVerifier
{
    public function __construct(
        private JwksKeyProvider $keyProvider,
        private string $issuer,
        private string $audience,
    ) {
    }

    public function verify(string $token): AuthenticatedPrincipal
    {
        if ($token === '' || trim($token) !== $token) {
            throw new InvalidTokenException('The bearer token is malformed.');
        }

        $keys = $this->keyProvider->getKeys();

        try {
            $claims = JWT::decode($token, $keys);
        } catch (Throwable $exception) {
            throw new InvalidTokenException('The bearer token could not be verified.', 0, $exception);
        }

        if (!isset($claims->iss) || !is_string($claims->iss) || !hash_equals($this->issuer, $claims->iss)) {
            throw new InvalidTokenException('The token issuer is not trusted.');
        }

        if (!isset($claims->aud)) {
            throw new InvalidTokenException('The token does not contain an audience.');
        }

        $audiences = is_array($claims->aud) ? $claims->aud : [$claims->aud];
        if (!in_array($this->audience, $audiences, true)) {
            throw new InvalidTokenException('The token was not issued for this service.');
        }

        if (!isset($claims->sub) || !is_string($claims->sub) || trim($claims->sub) === '') {
            throw new InvalidTokenException('The token does not contain a valid subject.');
        }

        if (!isset($claims->exp) || !is_numeric($claims->exp)) {
            throw new InvalidTokenException('The token does not contain a valid expiration time.');
        }

        $tenantId = isset($claims->tenant_id) && is_string($claims->tenant_id)
            ? $claims->tenant_id
            : null;

        $scopeClaim = isset($claims->scope) && is_string($claims->scope)
            ? trim($claims->scope)
            : '';

        $scopes = $scopeClaim === '' ? [] : preg_split('/\s+/', $scopeClaim);
        if ($scopes === false) {
            $scopes = [];
        }

        return new AuthenticatedPrincipal($claims->sub, $tenantId, array_values($scopes));
    }
}
