<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class JwksKeyProvider
{
    /** @var array<string, Key>|null */
    private ?array $keys = null;

    private float $expiresAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $jwksUri,
        private readonly int $cacheTtlSeconds = 300,
    ) {
    }

    /**
     * @return array<string, Key>
     */
    public function getKeys(): array
    {
        if ($this->keys !== null && microtime(true) < $this->expiresAt) {
            return $this->keys;
        }

        try {
            $response = $this->httpClient->request('GET', $this->jwksUri, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 3.0,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new JwksUnavailableException('The identity provider returned an unsuccessful JWKS response.');
            }

            $document = $response->toArray(false);
            if (!isset($document['keys']) || !is_array($document['keys']) || $document['keys'] === []) {
                throw new JwksUnavailableException('The identity provider returned an invalid JWKS document.');
            }

            $keys = JWK::parseKeySet($document, 'RS256');
            if ($keys === []) {
                throw new JwksUnavailableException('The JWKS document contains no supported signing keys.');
            }

            $this->keys = $keys;
            $this->expiresAt = microtime(true) + $this->cacheTtlSeconds;

            return $this->keys;
        } catch (JwksUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new JwksUnavailableException('The identity provider signing keys are temporarily unavailable.', 0, $exception);
        }
    }
}
