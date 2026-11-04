<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\Offer;

use App\Security\KeycloakTokenExchangeProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once dirname(__DIR__, 3) . '/apps/offer-service/src/Security/KeycloakTokenExchangeProvider.php';

final class KeycloakTokenExchangeProviderTest extends TestCase
{
    public function testExchangesAccessTokenForPricingAudience(): void
    {
        $captured = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = [$method, $url, $options];

                return new MockResponse(json_encode([
                    'access_token' => 'exchanged.token.value',
                    'token_type' => 'Bearer',
                    'expires_in' => 240,
                ], JSON_THROW_ON_ERROR));
            },
        );
        $provider = new KeycloakTokenExchangeProvider(
            $client,
            'https://identity.example.test/token',
            'offer-workload',
            'local-placeholder',
            'autoflow-pricing',
        );

        self::assertSame('exchanged.token.value', $provider->exchangeForPricing('header.payload.signature'));
        self::assertSame('POST', $captured[0]);
        self::assertSame('offer-workload', $captured[2]['auth_basic'][0]);
        self::assertSame(
            'urn:ietf:params:oauth:grant-type:token-exchange',
            $captured[2]['body']['grant_type'],
        );
        self::assertSame('header.payload.signature', $captured[2]['body']['subject_token']);
        self::assertSame('autoflow-pricing', $captured[2]['body']['audience']);
        self::assertSame(0, $captured[2]['max_redirects']);
    }

    public function testRejectsMalformedSubjectTokenBeforeCallingIdentityProvider(): void
    {
        $client = new MockHttpClient(
            static function (): never {
                self::fail('The identity provider must not be called.');
            },
        );
        $provider = new KeycloakTokenExchangeProvider(
            $client,
            'https://identity.example.test/token',
            'offer-workload',
            'local-placeholder',
            'autoflow-pricing',
        );

        $this->expectException(RuntimeException::class);
        $provider->exchangeForPricing('not-a-token');
    }

    public function testRejectsResponseWithoutBearerToken(): void
    {
        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                json_encode(['access_token' => 'missing-metadata'], JSON_THROW_ON_ERROR),
            ),
        );
        $provider = new KeycloakTokenExchangeProvider(
            $client,
            'https://identity.example.test/token',
            'offer-workload',
            'local-placeholder',
            'autoflow-pricing',
        );

        $this->expectException(RuntimeException::class);
        $provider->exchangeForPricing('header.payload.signature');
    }
}
