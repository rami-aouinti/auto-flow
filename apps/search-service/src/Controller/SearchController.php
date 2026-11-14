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
    public function __construct(private ElasticsearchClient $elasticsearch)
    {
    }

    #[Route('/api/v1/search/vehicles', name: 'search_vehicles', methods: ['GET'])]
    public function vehicles(Request $request): JsonResponse
    {
        $authorizationError = $this->authorize($request);
        if ($authorizationError !== null) {
            return $authorizationError;
        }

        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) > 120) {
            return $this->error(400, 'Invalid query', 'The q parameter must not exceed 120 characters.');
        }

        $page = $this->integerParameter($request, 'page', 1, 1, 10000);
        $limit = $this->integerParameter($request, 'limit', 20, 1, 100);
        if ($page === null || $limit === null) {
            return $this->error(400, 'Invalid pagination', 'page must be 1..10000 and limit must be 1..100.');
        }
        if ((($page - 1) * $limit) + $limit > 10000) {
            return $this->error(400, 'Pagination window exceeded', 'Search pagination is limited to the first 10,000 results.');
        }

        $filters = [];
        foreach ([
            'manufacturer' => 'manufacturer.keyword',
            'fuelType' => 'fuel_type',
            'powertrain' => 'powertrain',
            'bodyStyle' => 'body_style',
            'transmission' => 'transmission',
        ] as $parameter => $field) {
            $value = trim((string) $request->query->get($parameter, ''));
            if ($value !== '') {
                $filters[] = ['term' => [$field => $value]];
            }
        }

        foreach ([
            'minPowerKw' => ['power_kw', 'gte', 0, 10000],
            'maxPowerKw' => ['power_kw', 'lte', 0, 10000],
            'seats' => ['seats', 'eq', 1, 20],
        ] as $parameter => [$field, $operator, $minimum, $maximum]) {
            $raw = $request->query->get($parameter);
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if ($value === false || $value < $minimum || $value > $maximum) {
                return $this->error(400, 'Invalid filter', sprintf('%s must be an integer between %d and %d.', $parameter, $minimum, $maximum));
            }

            $filters[] = $operator === 'eq'
                ? ['term' => [$field => $value]]
                : ['range' => [$field => [$operator => $value]]];
        }

        $sort = (string) $request->query->get('sort', 'relevance');
        $sortMap = [
            'power_asc' => ['power_kw' => ['order' => 'asc', 'unmapped_type' => 'integer']],
            'power_desc' => ['power_kw' => ['order' => 'desc', 'unmapped_type' => 'integer']],
            'name' => ['name.keyword' => 'asc'],
        ];
        if ($sort !== 'relevance' && !isset($sortMap[$sort])) {
            return $this->error(400, 'Invalid sort', 'Supported sort values: relevance, power_asc, power_desc, name.');
        }

        $must = $query === ''
            ? [['match_all' => (object) []]]
            : [['multi_match' => [
                'query' => $query,
                'fields' => ['manufacturer^4', 'model^4', 'generation^2', 'name^2', 'search_text'],
                'fuzziness' => 'AUTO',
            ]]];

        try {
            $result = $this->elasticsearch->search([
                'from' => ($page - 1) * $limit,
                'size' => $limit,
                'query' => ['bool' => ['must' => $must, 'filter' => $filters]],
                'sort' => $sort === 'relevance'
                    ? [['_score' => 'desc'], ['vehicle_id' => 'asc']]
                    : [$sortMap[$sort], ['_id' => 'asc']],
                'aggs' => [
                    'fuel_types' => ['terms' => ['field' => 'fuel_type']],
                    'body_styles' => ['terms' => ['field' => 'body_style']],
                    'manufacturers' => ['terms' => ['field' => 'manufacturer.keyword', 'size' => 50]],
                ],
            ]);

            return new JsonResponse([
                'data' => array_map(
                    static fn (array $hit): array => ['id' => $hit['_id'], ...($hit['_source'] ?? [])],
                    $result['hits']['hits'] ?? [],
                ),
                'meta' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $result['hits']['total']['value'] ?? 0,
                ],
                'facets' => array_map(
                    static fn (array $aggregation): array => $aggregation['buckets'] ?? [],
                    $result['aggregations'] ?? [],
                ),
            ]);
        } catch (\Throwable) {
            return $this->error(503, 'Search unavailable', 'The search index is temporarily unavailable.');
        }
    }

    #[Route('/api/v1/search/vehicles/suggest', name: 'search_vehicle_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $authorizationError = $this->authorize($request);
        if ($authorizationError !== null) {
            return $authorizationError;
        }

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '' || mb_strlen($query) > 80) {
            return $this->error(400, 'Invalid query', 'q is required and must not exceed 80 characters.');
        }

        try {
            $result = $this->elasticsearch->search([
                'size' => 8,
                '_source' => ['vehicle_id', 'manufacturer', 'model', 'name', 'fuel_type', 'powertrain', 'body_style'],
                'query' => ['multi_match' => [
                    'query' => $query,
                    'type' => 'bool_prefix',
                    'fields' => ['manufacturer', 'model', 'name', 'search_text'],
                ]],
            ]);

            return new JsonResponse([
                'data' => array_map(
                    static fn (array $hit): array => ['id' => $hit['_id'], ...($hit['_source'] ?? [])],
                    $result['hits']['hits'] ?? [],
                ),
            ]);
        } catch (\Throwable) {
            return $this->error(503, 'Search unavailable', 'The search index is temporarily unavailable.');
        }
    }

    private function authorize(Request $request): ?JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal) {
            return $this->error(401, 'Unauthorized', 'A valid bearer token is required.');
        }

        if (!$principal->hasScope('catalog:read') && !$principal->hasScope('search:read')) {
            return $this->error(403, 'Forbidden', 'The token requires catalog:read or search:read.');
        }

        return null;
    }

    private function integerParameter(Request $request, string $name, int $default, int $minimum, int $maximum): ?int
    {
        $raw = $request->query->get($name, (string) $default);
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return $value !== false && $value >= $minimum && $value <= $maximum ? $value : null;
    }

    private function error(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
