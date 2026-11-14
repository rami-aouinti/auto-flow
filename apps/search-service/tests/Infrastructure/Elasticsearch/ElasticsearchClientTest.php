<?php
declare(strict_types=1);

namespace App\Tests\Infrastructure\Elasticsearch;

use App\Infrastructure\Elasticsearch\ElasticsearchClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ElasticsearchClientTest extends TestCase
{
    #[Test]
    public function pingReturnsTrueWhenElasticsearchRespondsSuccessfully(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->with('GET', 'http://elasticsearch:9200')->willReturn($response);

        self::assertTrue((new ElasticsearchClient($http, 'http://elasticsearch:9200', 'autoflow-vehicles-v1'))->ping());
    }

    #[Test]
    public function pingReturnsFalseWhenElasticsearchCannotBeReached(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willThrowException(new \RuntimeException('connection refused'));

        self::assertFalse((new ElasticsearchClient($http, 'http://elasticsearch:9200', 'autoflow-vehicles-v1'))->ping());
    }

    #[Test]
    public function searchDecodesTheElasticsearchResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn('{"hits":{"total":{"value":2},"hits":[]}}');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->with(
            'POST',
            'http://elasticsearch:9200/autoflow-vehicles-v1/_search',
            ['json' => ['query' => ['match_all' => (object) []]]],
        )->willReturn($response);

        $result = (new ElasticsearchClient($http, 'http://elasticsearch:9200', 'autoflow-vehicles-v1'))
            ->search(['query' => ['match_all' => (object) []]]);

        self::assertSame(2, $result['hits']['total']['value']);
    }
}
