<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use AutoFlow\CoreBundle\Security\RequiresScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Administrative CRUD for the authoritative vehicle catalogue.
 *
 * DELETE is deliberately a soft delete (status=archived): catalogue identifiers
 * may already be referenced by offers and orders and must remain stable.
 */
#[Route('/api/v1/admin/catalog')]
final readonly class CatalogAdminController
{
    private const RESOURCES = [
        'manufacturers' => [
            'table' => 'catalog_manufacturers', 'id' => 'manufacturer_id',
            'fields' => ['code' => 'code', 'name' => 'name', 'status' => 'status'],
            'select' => 'm.manufacturer_id AS id, m.code, m.name, m.status, m.created_at, m.updated_at',
            'from' => 'catalog_manufacturers m',
        ],
        'models' => [
            'table' => 'catalog_models', 'id' => 'model_id',
            'fields' => ['manufacturerId' => 'manufacturer_id', 'name' => 'name', 'status' => 'status'],
            'select' => 'm.model_id AS id, m.manufacturer_id AS "manufacturerId", m.name, m.status, m.created_at, m.updated_at, p.name AS "manufacturerName"',
            'from' => 'catalog_models m LEFT JOIN catalog_manufacturers p ON p.manufacturer_id=m.manufacturer_id',
        ],
        'generations' => [
            'table' => 'catalog_generations', 'id' => 'generation_id',
            'fields' => ['modelId' => 'model_id', 'name' => 'name', 'productionStartYear' => 'production_start_year', 'productionEndYear' => 'production_end_year', 'status' => 'status'],
            'select' => 'g.generation_id AS id, g.model_id AS "modelId", g.name, g.production_start_year AS "productionStartYear", g.production_end_year AS "productionEndYear", g.status, g.created_at, g.updated_at, m.name AS "modelName", b.name AS "manufacturerName"',
            'from' => 'catalog_generations g LEFT JOIN catalog_models m ON m.model_id=g.model_id LEFT JOIN catalog_manufacturers b ON b.manufacturer_id=m.manufacturer_id',
        ],
        'variants' => [
            'table' => 'catalog_vehicle_variants', 'id' => 'variant_id',
            'fields' => [
                'generationId' => 'generation_id', 'manufacturerCode' => 'manufacturer_code', 'name' => 'name',
                'fuelType' => 'fuel_type', 'powertrain' => 'powertrain', 'bodyStyle' => 'body_style',
                'transmission' => 'transmission', 'powerKw' => 'power_kw', 'displacementCc' => 'displacement_cc',
                'seats' => 'seats', 'doors' => 'doors', 'curbWeightKg' => 'curb_weight_kg',
                'lengthMm' => 'length_mm', 'widthMm' => 'width_mm', 'heightMm' => 'height_mm',
                'co2EmissionGPerKm' => 'co2_emission_g_per_km', 'mediaReferences' => 'media_references', 'status' => 'status',
            ],
            'select' => 'v.variant_id AS id, v.generation_id AS "generationId", v.manufacturer_code AS "manufacturerCode", v.name, v.fuel_type AS "fuelType", v.powertrain, v.body_style AS "bodyStyle", v.transmission, v.power_kw AS "powerKw", v.displacement_cc AS "displacementCc", v.seats, v.doors, v.curb_weight_kg AS "curbWeightKg", v.length_mm AS "lengthMm", v.width_mm AS "widthMm", v.height_mm AS "heightMm", v.co2_emission_g_per_km AS "co2EmissionGPerKm", v.media_references AS "mediaReferences", v.status, v.created_at, v.updated_at, g.name AS "generationName", m.name AS "modelName", b.name AS "manufacturerName"',
            'from' => 'catalog_vehicle_variants v LEFT JOIN catalog_generations g ON g.generation_id=v.generation_id LEFT JOIN catalog_models m ON m.model_id=g.model_id LEFT JOIN catalog_manufacturers b ON b.manufacturer_id=m.manufacturer_id',
        ],
    ];

    #[RequiresScope('catalog:manage')]
    #[Route('/{resource}', methods: ['GET'], requirements: ['resource' => 'manufacturers|models|generations|variants'])]
    #[RequiresScope('catalog:manage')]
    #[Route('/{resource}', methods: ['POST'], requirements: ['resource' => 'manufacturers|models|generations|variants'])]
    public function create(string $resource, Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $input = $request->toArray();
            $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
            if (!$principal instanceof AuthenticatedPrincipal) {
                return $this->problem(403, 'An authenticated catalogue principal is required.');
            }
            $actor = new CatalogActor($principal->subject, $principal->scopes);

            $id = match ($resource) {
                'manufacturers' => (function () use ($catalog, $actor, $input): string {
                    $data = CreateManufacturerRequest::fromArray($input);
                    return $catalog->registerManufacturer($actor, $data->code, $data->name);
                })(),
                'models' => (function () use ($catalog, $actor, $input): string {
                    $data = CreateModelRequest::fromArray($input);
                    return $catalog->registerModel($actor, $data->manufacturerId, $data->name);
                })(),
                'generations' => (function () use ($catalog, $actor, $input): string {
                    $data = CreateGenerationRequest::fromArray($input);
                    return $catalog->registerGeneration($actor, $data->modelId, $data->name, $data->productionStartYear, $data->productionEndYear);
                })(),
                'variants' => $catalog->registerVariant($actor, CreateVehicleVariantRequest::fromArray($input)->toArray()),
            };

            return new JsonResponse(['id' => $id, 'status' => 'draft'], 201, ['Cache-Control' => 'no-store']);
        } catch (CatalogParentNotFoundException $exception) {
            return $this->problem(422, $exception->getMessage());
        } catch (\\InvalidArgumentException $exception) {
            return $this->problem(422, $exception->getMessage());
        } catch (\\Throwable) {
            return $this->problem(400, 'A valid catalogue JSON payload is required.');
        }
    }

    public function list(string $resource, Request $request, Connection $db): JsonResponse
    {
        $config = self::RESOURCES[$resource];
        $limit = filter_var($request->query->get('limit', '50'), FILTER_VALIDATE_INT);
        $offset = filter_var($request->query->get('offset', '0'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1 || $limit > 100 || !is_int($offset) || $offset < 0) {
            return $this->problem(400, 'limit must be 1..100 and offset must be >= 0.');
        }

        try {
            $total = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $config['table']);
            $rows = $db->fetchAllAssociative(
                'SELECT ' . $config['select'] . ' FROM ' . $config['from'] .
                ' ORDER BY ' . $this->sortColumn($resource) . ' LIMIT :limit OFFSET :offset',
                ['limit' => $limit, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            );
            return new JsonResponse(['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
        } catch (Throwable) {
            return $this->problem(500, 'Catalogue records could not be loaded.');
        }
    }

    #[RequiresScope('catalog:manage')]
    #[Route('/{resource}/{id}', methods: ['GET'], requirements: ['resource' => 'manufacturers|models|generations|variants', 'id' => '[a-f0-9]{32}'])]
    public function get(string $resource, string $id, Connection $db): JsonResponse
    {
        $config = self::RESOURCES[$resource];
        try {
            $row = $db->fetchAssociative(
                'SELECT ' . $config['select'] . ' FROM ' . $config['from'] . ' WHERE ' . $this->idColumn($resource) . '=:id',
                ['id' => $id],
            );
            return $row === false ? $this->problem(404, 'Catalogue record not found.') : new JsonResponse($row);
        } catch (Throwable) {
            return $this->problem(500, 'Catalogue record could not be loaded.');
        }
    }

    #[RequiresScope('catalog:manage')]
    #[Route('/{resource}/{id}', methods: ['PATCH'], requirements: ['resource' => 'manufacturers|models|generations|variants', 'id' => '[a-f0-9]{32}'])]
    public function update(string $resource, string $id, Request $request, Connection $db): JsonResponse
    {
        $config = self::RESOURCES[$resource];
        try {
            $input = $request->toArray();
            $sets = [];
            $params = ['id' => $id];
            foreach ($input as $field => $value) {
                if (!isset($config['fields'][$field])) {
                    return $this->problem(400, sprintf('Field "%s" is not editable for %s.', $field, $resource));
                }
                if ($field === 'status' && !in_array($value, ['draft', 'published', 'discontinued', 'archived'], true)) {
                    return $this->problem(422, 'Unsupported catalogue status.');
                }
                if (in_array($field, ['name', 'code'], true) && (!is_string($value) || trim($value) === '')) {
                    return $this->problem(422, sprintf('%s must be a non-empty string.', $field));
                }
                if (str_ends_with($config['fields'][$field], '_year') || in_array($field, ['powerKw','displacementCc','seats','doors','curbWeightKg','lengthMm','widthMm','heightMm','co2EmissionGPerKm'], true)) {
                    if ($value !== null && !is_int($value)) return $this->problem(422, sprintf('%s must be an integer or null.', $field));
                }
                $column = $config['fields'][$field];
                if ($field === 'mediaReferences') {
                    if (!is_array($value)) return $this->problem(422, 'mediaReferences must be an array.');
                    $value = json_encode($value, JSON_THROW_ON_ERROR);
                }
                $sets[] = $column . '=:' . $field;
                $params[$field] = $value;
                if ($resource === 'models' && $field === 'name') {
                    $sets[] = 'normalized_name=:normalizedName';
                    $params['normalizedName'] = mb_strtolower(trim($value));
                }
            }
            if ($sets === []) return $this->problem(400, 'At least one editable field is required.');
            $sets[] = 'updated_at=CURRENT_TIMESTAMP';
            $changed = $db->executeStatement(
                'UPDATE ' . $config['table'] . ' SET ' . implode(', ', $sets) . ' WHERE ' . $config['id'] . '=:id',
                $params,
            );
            if ($changed === 0 && !$db->fetchOne('SELECT 1 FROM ' . $config['table'] . ' WHERE ' . $config['id'] . '=:id', ['id' => $id])) {
                return $this->problem(404, 'Catalogue record not found.');
            }
            return $this->get($resource, $id, $db);
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'A catalogue record with this code or parent/name already exists.');
        } catch (ForeignKeyConstraintViolationException) {
            return $this->problem(422, 'The selected parent record does not exist.');
        } catch (Throwable) {
            return $this->problem(422, 'Catalogue update failed. Check field values and parent references.');
        }
    }

    #[RequiresScope('catalog:manage')]
    #[Route('/{resource}/{id}', methods: ['DELETE'], requirements: ['resource' => 'manufacturers|models|generations|variants', 'id' => '[a-f0-9]{32}'])]
    public function archive(string $resource, string $id, Connection $db): JsonResponse
    {
        $config = self::RESOURCES[$resource];
        try {
            $changed = $db->executeStatement(
                'UPDATE ' . $config['table'] . " SET status='archived', updated_at=CURRENT_TIMESTAMP WHERE " . $config['id'] . '=:id',
                ['id' => $id],
            );
            return $changed === 0
                ? $this->problem(404, 'Catalogue record not found.')
                : new JsonResponse(['id' => $id, 'status' => 'archived']);
        } catch (Throwable) {
            return $this->problem(409, 'Record could not be archived because it is referenced or unavailable.');
        }
    }

    private function idColumn(string $resource): string
    {
        return self::RESOURCES[$resource]['id'];
    }

    private function sortColumn(string $resource): string
    {
        return match ($resource) {
            'manufacturers' => 'm.name',
            'models' => 'p.name, m.name',
            'generations' => 'b.name, m.name, g.name',
            default => 'b.name, m.name, g.name, v.name',
        };
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => match ($status) { 400 => 'Bad Request', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Entity', default => 'Internal Server Error' },
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
