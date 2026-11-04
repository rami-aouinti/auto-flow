<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\Offer;

use App\Security\KeycloakTokenExchangeProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KeycloakTokenExchangeProviderTest extends TestCase
{
    public function testExchangesAccessTokenForPricingAudience(): void
    {
        $method = '';
        $capturedUrl = '';
        $capturedOptions = null;
        $client = new MockHttpClient(
            static function (
                string $requestMethod,
                string $url,
                array|string $options,
            ) use (
                &$method,
                &$capturedUrl,
                &$capturedOptions,
            ): MockResponse {
                $method = $requestMethod;
                $capturedUrl = $url;
                $capturedOptions = $options;

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
        self::assertSame('POST', $method);
        self::assertSame('https://identity.example.test/token', $capturedUrl);
        self::assertIsArray($capturedOptions);

        $body = $capturedOptions['body'] ?? null;
        $bodyParameters = [];
        if (is_string($body)) {
            parse_str($body, $bodyParameters);
        }

        if (is_array($body)) {
            $bodyParameters = $body;
        }
        self::assertSame(
            'urn:ietf:params:oauth:grant-type:token-exchange',
            $bodyParameters['grant_type'] ?? null,
        );
        self::assertSame('header.payload.signature', $bodyParameters['subject_token'] ?? null);
        self::assertSame('autoflow-pricing', $bodyParameters['audience'] ?? null);
        self::assertSame(0, $capturedOptions['max_redirects'] ?? null);
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
