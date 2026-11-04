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

        $rsa = $details['rsa'];
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

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($jwks, JSON_THROW_ON_ERROR), [
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

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
