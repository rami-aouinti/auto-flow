<?php
declare(strict_types=1);
namespace App\Controller;
use App\Infrastructure\Elasticsearch\ElasticsearchClient;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
final readonly class SearchController
{
    public function __construct(private ElasticsearchClient $elasticsearch) {}
    #[Route('/api/v1/search/vehicles', name: 'search_vehicles', methods: ['GET'])]
    public function vehicles(Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal) return $this->error(401, 'Unauthorized', 'A valid bearer token is required.');
        if (!$principal->hasScope('catalog:read') && !$principal->hasScope('search:read')) return $this->error(403, 'Forbidden', 'The token requires catalog:read or search:read.');
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) > 120) return $this->error(400, 'Invalid query', 'The q parameter must not exceed 120 characters.');
        $page = max(1, min(10000, (int) $request->query->get('page', 1)));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $filters = [];
        foreach (['manufacturer' => 'manufacturer.keyword', 'fuelType' => 'fuel_type', 'powertrain' => 'powertrain', 'bodyStyle' => 'body_style', 'transmission' => 'transmission'] as $param => $field) {
            $value = trim((string) $request->query->get($param, ''));
            if ($value !== '') $filters[] = ['term' => [$field => $value]];
        }
        foreach (['minPowerKw' => ['power_kw', 'gte'], 'maxPowerKw' => ['power_kw', 'lte'], 'seats' => ['seats', 'eq']] as $param => [$field, $operator]) {
            $raw = $request->query->get($param);
            if ($raw === null || $raw === '') continue;
            if (!is_numeric($raw) || (int) $raw < 0 || (int) $raw > 10000) return $this->error(400, 'Invalid filter', $param . ' must be an integer between 0 and 10000.');
            $filters[] = $operator === 'eq' ? ['term' => [$field => (int) $raw]] : ['range' => [$field => [$operator => (int) $raw]]];
        }
        $sort = (string) $request->query->get('sort', 'relevance');
        $sortMap = ['power_asc' => ['power_kw' => ['order' => 'asc', 'unmapped_type' => 'integer']], 'power_desc' => ['power_kw' => ['order' => 'desc', 'unmapped_type' => 'integer']], 'name' => ['name.keyword' => 'asc']];
        if ($sort !== 'relevance' && !isset($sortMap[$sort])) return $this->error(400, 'Invalid sort', 'Supported sort values: relevance, power_asc, power_desc, name.');
        $must = $q === '' ? [['match_all' => (object) []]] : [['multi_match' => ['query' => $q, 'fields' => ['manufacturer^4', 'model^4', 'generation^2', 'name^2', 'search_text'], 'fuzziness' => 'AUTO']]];
        try {
            $result = $this->elasticsearch->search([
                'from' => ($page - 1) * $limit, 'size' => $limit,
                'query' => ['bool' => ['must' => $must, 'filter' => $filters]],
                'sort' => $sort === 'relevance' ? [['_score' => 'desc']] : [$sortMap[$sort]],
                'aggs' => ['fuel_types' => ['terms' => ['field' => 'fuel_type']], 'body_styles' => ['terms' => ['field' => 'body_style']], 'manufacturers' => ['terms' => ['field' => 'manufacturer.keyword', 'size' => 50]]],
            ]);
            return new JsonResponse([
                'data' => array_map(static fn(array $hit): array => ['id' => $hit['_id'], ...($hit['_source'] ?? [])], $result['hits']['hits'] ?? []),
                'meta' => ['page' => $page, 'limit' => $limit, 'total' => $result['hits']['total']['value'] ?? 0],
                'facets' => array_map(static fn(array $agg): array => $agg['buckets'] ?? [], $result['aggregations'] ?? []),
            ]);
        } catch (\Throwable) { return $this->error(503, 'Search unavailable', 'The search index is temporarily unavailable.'); }
    }
    #[Route('/api/v1/search/vehicles/suggest', name: 'search_vehicle_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal) return $this->error(401, 'Unauthorized', 'A valid bearer token is required.');
        if (!$principal->hasScope('catalog:read') && !$principal->hasScope('search:read')) return $this->error(403, 'Forbidden', 'The token requires catalog:read or search:read.');
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '' || mb_strlen($q) > 80) return $this->error(400, 'Invalid query', 'q is required and must not exceed 80 characters.');
        try {
            $result = $this->elasticsearch->search(['size' => 8, '_source' => ['vehicle_id','manufacturer','model','name','fuel_type','powertrain','body_style'], 'query' => ['multi_match' => ['query' => $q, 'type' => 'bool_prefix', 'fields' => ['manufacturer','model','name','search_text']]]]);
            return new JsonResponse(['data' => array_map(static fn(array $hit): array => ['id' => $hit['_id'], ...($hit['_source'] ?? [])], $result['hits']['hits'] ?? [])]);
        } catch (\Throwable) { return $this->error(503, 'Search unavailable', 'The search index is temporarily unavailable.'); }
    }
    private function error(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail], $status, ['Content-Type' => 'application/problem+json']);
    }
}
