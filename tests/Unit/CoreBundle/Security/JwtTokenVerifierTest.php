<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Security;

use AutoFlow\CoreBundle\Security\InvalidTokenException;
use AutoFlow\CoreBundle\Security\JwksKeyProvider;
use AutoFlow\CoreBundle\Security\JwtTokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class JwtTokenVerifierTest extends TestCase
{
    public function testItVerifiesSignatureIssuerAudienceAndPrincipalClaims(): void
    {
        [$verifier, $token] = $this->createVerifier([
            'iss' => 'https://identity.autoflow.test/realms/autoflow',
            'aud' => ['autoflow-pricing'],
            'sub' => 'user-123',
            'tenant_id' => 'tenant-acme',
            'scope' => 'pricing:quote offers:read',
            'iat' => time(),
            'exp' => time() + 300,
        ]);

        $principal = $verifier->verify($token);

        self::assertSame('user-123', $principal->subject);
        self::assertSame('tenant-acme', $principal->tenantId);
        self::assertTrue($principal->hasScope('pricing:quote'));
        self::assertFalse($principal->hasScope('contracts:write'));
    }

    public function testItRejectsAnEmptyTenantIdentifier(): void
    {
        $this->assertInvalidTenantIdentifier(' ');
    }

    public function testItRejectsAMalformedTenantIdentifier(): void
    {
        $this->assertInvalidTenantIdentifier('tenant/acme');
    }

    public function testItRejectsAnOverlongKeyIdentifierBeforeFetchingJwks(): void
    {
        $keyPair = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($keyPair);

        $token = JWT::encode([
            'iss' => 'https://identity.autoflow.test/realms/autoflow',
            'aud' => ['autoflow-pricing'],
            'sub' => 'user-123',
            'iat' => time(),
            'exp' => time() + 300,
        ], $keyPair, 'RS256', str_repeat('a', 129));

        $httpClient = new MockHttpClient();
        $verifier = new JwtTokenVerifier(
            new JwksKeyProvider($httpClient, 'https://identity.autoflow.test/jwks'),
            'https://identity.autoflow.test/realms/autoflow',
            'autoflow-pricing',
        );

        try {
            $verifier->verify($token);
            self::fail('An overlong kid must be rejected.');
        } catch (InvalidTokenException) {
            self::assertSame(0, $httpClient->getRequestsCount());
        }
    }

    public function testItRefreshesJwksWhenKeycloakRotatesToANewKeyId(): void
    {
        $oldKeyPair = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $newKeyPair = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($oldKeyPair);
        self::assertNotFalse($newKeyPair);

        $oldJwk = $this->createJwk($oldKeyPair, 'old-key');
        $newJwk = $this->createJwk($newKeyPair, 'rotated-key');
        $requests = 0;
        $httpClient = new MockHttpClient(
            static function () use (&$requests, $oldJwk, $newJwk): MockResponse {
                ++$requests;
                $keys = $requests === 1 ? [$oldJwk] : [$oldJwk, $newJwk];

                return new MockResponse(
                    json_encode(['keys' => $keys], JSON_THROW_ON_ERROR),
                    ['http_code' => 200, 'response_headers' => ['content-type: application/json']],
                );
            },
        );

        $token = JWT::encode([
            'iss' => 'https://identity.autoflow.test/realms/autoflow',
            'aud' => ['autoflow-pricing'],
            'sub' => 'user-rotated',
            'iat' => time(),
            'exp' => time() + 300,
        ], $newKeyPair, 'RS256', 'rotated-key');

        $verifier = new JwtTokenVerifier(
            new JwksKeyProvider($httpClient, 'https://identity.autoflow.test/jwks', 300),
            'https://identity.autoflow.test/realms/autoflow',
            'autoflow-pricing',
        );

        $principal = $verifier->verify($token);

        self::assertSame('user-rotated', $principal->subject);
        self::assertSame(2, $requests, 'An unknown kid should trigger one JWKS refresh.');
    }

    public function testItRejectsATokenForAnotherAudience(): void
    {
        [$verifier, $token] = $this->createVerifier([
            'iss' => 'https://identity.autoflow.test/realms/autoflow',
            'aud' => ['autoflow-offer'],
            'sub' => 'user-123',
            'iat' => time(),
            'exp' => time() + 300,
        ]);

        $this->expectException(InvalidTokenException::class);

        $verifier->verify($token);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertInvalidTenantIdentifier(string $tenantId): void
    {
        [$verifier, $token] = $this->createVerifier([
            'iss' => 'https://identity.autoflow.test/realms/autoflow',
            'aud' => ['autoflow-pricing'],
            'sub' => 'user-123',
            'tenant_id' => $tenantId,
            'iat' => time(),
            'exp' => time() + 300,
        ]);

        $this->expectException(InvalidTokenException::class);
        $verifier->verify($token);
    }

    /**
     * @param array<string, mixed> $claims
     * @return array{JwtTokenVerifier, string}
     */
    private function createVerifier(array $claims): array
    {
        $keyPair = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($keyPair);

        $details = openssl_pkey_get_details($keyPair);
        self::assertIsArray($details);
        self::assertArrayHasKey('rsa', $details);

        $rsa = $details['rsa'] ?? null;
        self::assertIsArray($rsa);
        self::assertIsString($rsa['n'] ?? null);
        self::assertIsString($rsa['e'] ?? null);
        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => 'autoflow-test-key',
                'n' => $this->base64UrlEncode($rsa['n']),
                'e' => $this->base64UrlEncode($rsa['e']),
            ]],
        ];

        $jwksJson = json_encode($jwks, JSON_THROW_ON_ERROR);
        $httpClient = new MockHttpClient([
            new MockResponse($jwksJson, [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json'],
            ]),
        ]);
        $keyProvider = new JwksKeyProvider($httpClient, 'https://identity.autoflow.test/jwks', 300);
        $token = JWT::encode($claims, $keyPair, 'RS256', 'autoflow-test-key');

        return [
            new JwtTokenVerifier(
                $keyProvider,
                'https://identity.autoflow.test/realms/autoflow',
                'autoflow-pricing',
            ),
            $token,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function createJwk(\OpenSSLAsymmetricKey $keyPair, string $keyId): array
    {
        $details = openssl_pkey_get_details($keyPair);
        self::assertIsArray($details);
        self::assertIsArray($details['rsa'] ?? null);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $keyId,
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
