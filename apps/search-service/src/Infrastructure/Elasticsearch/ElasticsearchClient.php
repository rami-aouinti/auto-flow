<?php
declare(strict_types=1);
namespace App\Infrastructure\Elasticsearch;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
final readonly class ElasticsearchClient
{
    public function __construct(private HttpClientInterface $http, private string $baseUri, private string $indexName) {}
    public function ping(): bool
    {
        try { return $this->http->request('GET', rtrim($this->baseUri, '/'))->getStatusCode() === 200; }
        catch (\Throwable) { return false; }
    }
    public function ensureVehicleIndex(): void
    {
        $response = $this->http->request('PUT', $this->url($this->indexName), ['json' => [
            'settings' => ['analysis' => ['analyzer' => ['vehicle_text' => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding']]]]],
            'mappings' => ['dynamic' => 'strict', 'properties' => [
                'vehicle_id' => ['type' => 'keyword'],
                'manufacturer' => ['type' => 'text', 'analyzer' => 'vehicle_text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'model' => ['type' => 'text', 'analyzer' => 'vehicle_text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'generation' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'name' => ['type' => 'text', 'analyzer' => 'vehicle_text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                'search_text' => ['type' => 'text', 'analyzer' => 'vehicle_text'],
                'fuel_type' => ['type' => 'keyword'], 'powertrain' => ['type' => 'keyword'],
                'body_style' => ['type' => 'keyword'], 'transmission' => ['type' => 'keyword'],
                'power_kw' => ['type' => 'integer'], 'seats' => ['type' => 'integer'],
                'doors' => ['type' => 'integer'], 'co2_emission_g_per_km' => ['type' => 'integer'],
                'production_start_year' => ['type' => 'integer'], 'production_end_year' => ['type' => 'integer'],
                'status' => ['type' => 'keyword'], 'updated_at' => ['type' => 'date'],
            ]],
        ]]);
        $this->assertSuccess($response, 'create vehicle index');
    }
    public function ensureInboxIndex(): void
    {
        $response = $this->http->request('PUT', $this->url('autoflow-search-inbox-v1'), ['json' => [
            'mappings' => ['dynamic' => 'strict', 'properties' => [
                'event_id' => ['type' => 'keyword'],
                'event_type' => ['type' => 'keyword'],
                'processed_at' => ['type' => 'date'],
            ]],
        ]]);
        $status = $response->getStatusCode();
        if ($status !== 400) $this->assertSuccess($response, 'create search inbox index');
        else {
            $data = json_decode($response->getContent(false), true);
            if (!str_contains((string) ($data['error']['type'] ?? ''), 'resource_already_exists_exception')) {
                throw new RuntimeException('Elasticsearch could not initialize the Search inbox index.');
            }
        }
    }
    public function hasProcessedEvent(string $eventId): bool
    {
        $response = $this->http->request('GET', $this->url('autoflow-search-inbox-v1/_doc/' . rawurlencode($eventId)));
        $status = $response->getStatusCode();
        if ($status === 404) return false;
        $data = $this->decode($response);
        return ($data['found'] ?? false) === true;
    }
    public function markEventProcessed(string $eventId, string $eventType): void
    {
        $this->decode($this->http->request('PUT', $this->url('autoflow-search-inbox-v1/_doc/' . rawurlencode($eventId)), ['json' => [
            'event_id' => $eventId, 'event_type' => $eventType, 'processed_at' => gmdate(DATE_ATOM),
        ]]));
    }
    /** @param array<string,mixed> $query */
    public function search(array $query): array { return $this->decode($this->http->request('POST', $this->url($this->indexName . '/_search'), ['json' => $query])); }
    /** @param list<array{id:string,document:array<string,mixed>}> $documents */
    public function bulkIndex(array $documents): int
    {
        if ($documents === []) return 0;
        $body = '';
        foreach ($documents as $item) {
            $body .= json_encode(['index' => ['_index' => $this->indexName, '_id' => $item['id']]], JSON_THROW_ON_ERROR) . "\n";
            $body .= json_encode($item['document'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        }
        $data = $this->decode($this->http->request('POST', $this->url('_bulk'), ['headers' => ['Content-Type' => 'application/x-ndjson'], 'body' => $body]));
        if (($data['errors'] ?? true) === true) throw new RuntimeException('Elasticsearch bulk indexing reported item errors.');
        return count($documents);
    }
    public function activateAlias(string $alias, string $targetIndex): void
    {
        $this->decode($this->http->request('POST', $this->url('_aliases'), ['json' => ['actions' => [
            ['remove' => ['index' => $alias . '-*', 'alias' => $alias, 'must_exist' => false]],
            ['add' => ['index' => $targetIndex, 'alias' => $alias]],
        ]]]));
    }
    public function deleteIndex(string $index): void
    {
        $response = $this->http->request('DELETE', $this->url($index));
        $status = $response->getStatusCode();
        if ($status !== 404 && ($status < 200 || $status >= 300)) throw new RuntimeException('Elasticsearch could not delete temporary index: HTTP ' . $status . '.');
    }
    private function assertSuccess(ResponseInterface $response, string $operation): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) throw new RuntimeException(sprintf('Elasticsearch could not %s: HTTP %d.', $operation, $status));
    }
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) throw new RuntimeException('Elasticsearch request failed with HTTP ' . $status . '.');
        return $data;
    }
    private function url(string $path): string { return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/'); }
}
