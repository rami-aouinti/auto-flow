<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use Firebase\JWT\JWT;
use JsonException;
use stdClass;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
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

        $claims = $this->decode($token);
        $this->assertIssuer($claims);
        $this->assertAudience($claims);
        $this->assertExpiration($claims);

        return new AuthenticatedPrincipal(
            $this->extractSubject($claims),
            $this->extractTenantId($claims),
            $this->extractScopes($claims),
        );
    }

    private function decode(string $token): stdClass
    {
        try {
            $keyId = $this->extractKeyId($token);

            return JWT::decode($token, $this->keyProvider->getKeysForKeyId($keyId));
        } catch (JwksUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidTokenException('The bearer token could not be verified.', 0, $exception);
        }
    }

    private function extractKeyId(string $token): string
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new InvalidTokenException('The bearer token must be a signed JWT.');
        }

        $encodedHeader = strtr($segments[0], '-_', '+/');
        $encodedHeader .= str_repeat('=', (4 - strlen($encodedHeader) % 4) % 4);
        $headerJson = base64_decode($encodedHeader, true);
        if ($headerJson === false) {
            throw new InvalidTokenException('The bearer token header is malformed.');
        }

        try {
            $header = json_decode($headerJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidTokenException('The bearer token header is malformed.', 0, $exception);
        }

        if (!$header instanceof stdClass || !isset($header->kid) || !is_string($header->kid) || trim($header->kid) === '') {
            throw new InvalidTokenException('The bearer token does not contain a valid key identifier.');
        }

        return $header->kid;
    }

    private function assertIssuer(stdClass $claims): void
    {
        if (!isset($claims->iss) || !is_string($claims->iss) || !hash_equals($this->issuer, $claims->iss)) {
            throw new InvalidTokenException('The token issuer is not trusted.');
        }
    }

    private function assertAudience(stdClass $claims): void
    {
        if (!isset($claims->aud)) {
            throw new InvalidTokenException('The token does not contain an audience.');
        }

        $audiences = is_array($claims->aud) ? $claims->aud : [$claims->aud];
        if (!in_array($this->audience, $audiences, true)) {
            throw new InvalidTokenException('The token was not issued for this service.');
        }
    }

    private function assertExpiration(stdClass $claims): void
    {
        if (!isset($claims->exp) || !is_numeric($claims->exp)) {
            throw new InvalidTokenException('The token does not contain a valid expiration time.');
        }
    }

    private function extractSubject(stdClass $claims): string
    {
        if (!isset($claims->sub) || !is_string($claims->sub) || trim($claims->sub) === '') {
            throw new InvalidTokenException('The token does not contain a valid subject.');
        }

        return $claims->sub;
    }

    private function extractTenantId(stdClass $claims): ?string
    {
        return isset($claims->tenant_id) && is_string($claims->tenant_id)
            ? $claims->tenant_id
            : null;
    }

    /**
     * @return list<string>
     */
    private function extractScopes(stdClass $claims): array
    {
        $scopeClaim = isset($claims->scope) && is_string($claims->scope)
            ? trim($claims->scope)
            : '';

        if ($scopeClaim === '') {
            return [];
        }

        $scopes = preg_split('/\s+/', $scopeClaim);

        return $scopes === false ? [] : $scopes;
    }
}
