<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Http\Client;

use AutoFlow\CoreBundle\Context\CorrelationContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class CorrelationIdHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private CorrelationContext $context,
        private string $headerName,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $correlationId = $this->context->get();

        if ($correlationId !== null) {
            $options = $this->addCorrelationHeader($options, $correlationId);
        }

        return $this->inner->request($method, $url, $options);
    }

    public function stream(
        ResponseInterface|iterable $responses,
        ?float $timeout = null,
    ): \Symfony\Contracts\HttpClient\ResponseStreamInterface {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->context, $this->headerName);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function addCorrelationHeader(array $options, string $correlationId): array
    {
        $headers = $options['headers'] ?? [];

        if (!is_array($headers) || $this->containsCorrelationHeader($headers)) {
            return $options;
        }

        if (array_is_list($headers)) {
            $headers[] = $this->headerName . ': ' . $correlationId;
            $options['headers'] = $headers;

            return $options;
        }

        $headers[$this->headerName] = $correlationId;
        $options['headers'] = $headers;

        return $options;
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private function containsCorrelationHeader(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            $candidate = is_string($name) ? $name : (is_string($value) ? strtok($value, ':') : '');

            if (is_string($candidate) && strcasecmp(trim($candidate), $this->headerName) === 0) {
                return true;
            }
        }

        return false;
    }
}
