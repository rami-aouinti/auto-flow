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

        $header = $this->decodeHeader($segments[0]);
        if (!$header instanceof stdClass || !isset($header->kid) || !$this->isValidKeyId($header->kid)) {
            throw new InvalidTokenException('The bearer token does not contain a valid key identifier.');
        }

        return $header->kid;
    }

    private function decodeHeader(string $encodedHeader): mixed
    {
        $encodedHeader = strtr($encodedHeader, '-_', '+/');
        $encodedHeader .= str_repeat('=', (4 - strlen($encodedHeader) % 4) % 4);
        $headerJson = base64_decode($encodedHeader, true);
        if ($headerJson === false) {
            throw new InvalidTokenException('The bearer token header is malformed.');
        }

        try {
            return json_decode($headerJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidTokenException('The bearer token header is malformed.', 0, $exception);
        }
    }

    private function isValidKeyId(mixed $keyId): bool
    {
        return is_string($keyId)
            && trim($keyId) !== ''
            && strlen($keyId) <= 128
            && preg_match('/^[A-Za-z0-9._:-]+$/D', $keyId) === 1;
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
            // Keycloak access tokens should always carry the standard OIDC "sub"
            // claim. Log only claim names and the subject value's shape so the
            // token itself and all personal claim values remain private.
            error_log(sprintf(
                '[AutoFlow JWT] Invalid subject claim; claim_keys=%s; sub_type=%s; sub_empty=%s',
                json_encode(array_keys(get_object_vars($claims)), JSON_UNESCAPED_SLASHES) ?: '[]',
                get_debug_type($claims->sub ?? null),
                !isset($claims->sub) || $claims->sub === '',
            ));

            throw new InvalidTokenException('The token does not contain a valid subject.');
        }

        return $claims->sub;
    }

    private function extractTenantId(stdClass $claims): ?string
    {
        if (!property_exists($claims, 'tenant_id')) {
            return null;
        }

        if (
            !is_string($claims->tenant_id)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $claims->tenant_id) !== 1
        ) {
            throw new InvalidTokenException('The token contains an invalid tenant identifier.');
        }

        return $claims->tenant_id;
    }

    /**
     * @return list<string>
     */
    private function extractScopes(stdClass $claims): array
    {
        $scopeClaim = isset($claims->scope) && is_string($claims->scope)
            ? trim($claims->scope)
            : '';
        $scopes = $scopeClaim === '' ? [] : (preg_split('/\s+/', $scopeClaim) ?: []);

        $realmRoles = $claims->realm_access->roles ?? [];
        if (is_array($realmRoles)) {
            foreach ($realmRoles as $role) {
                if (is_string($role) && preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,127}$/D', $role) === 1) {
                    $scopes[] = $role;
                }
            }
        }

        return array_values(array_unique($scopes));
    }
}
