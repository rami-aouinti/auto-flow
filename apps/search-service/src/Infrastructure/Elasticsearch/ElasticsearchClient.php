<?php
declare(strict_types=1);
namespace App\Infrastructure\Elasticsearch;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use RuntimeException;
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
        $status = $response->getStatusCode();
        if ($status >= 400 && $status !== 400) throw new RuntimeException('Elasticsearch could not create the vehicle index.');
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
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) throw new RuntimeException('Elasticsearch request failed with HTTP ' . $status . '.');
        return $data;
    }
    private function url(string $path): string { return rtrim($this->baseUri, '/') . '/' . ltrim($path, '/'); }
}
