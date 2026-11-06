<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\CatalogApplicationService;
use App\Application\Exception\CatalogParentNotFoundException;
use App\Application\Security\CatalogActor;
use App\Presentation\Http\Dto\Request\CreateGenerationRequest;
use App\Presentation\Http\Dto\Request\CreateManufacturerRequest;
use App\Presentation\Http\Dto\Request\CreateModelRequest;
use App\Presentation\Http\Dto\Request\CreateVehicleVariantRequest;
use App\Presentation\Http\Dto\Request\InvalidPaginationException;
use App\Presentation\Http\Dto\Request\VehicleSearchCriteria;
use App\Presentation\Http\Dto\Response\ManufacturerResponse;
use App\Presentation\Http\Dto\Response\VehicleResponse;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\RequiresScope;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
#[Route('/api/v1')]
final readonly class CatalogController
{
    #[OA\Get(
        path: '/api/v1/manufacturers',
        summary: 'List published manufacturers',
        tags: ['Catalogue'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Published manufacturers',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/ManufacturerResponse'),
                ),
            ],
        ),
    )]
    #[RequiresScope('catalog:read')]
    #[Route('/manufacturers', methods: ['GET'])]
    public function manufacturers(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $items = array_map(
                static fn (array $item): array => ManufacturerResponse::fromArray($item)->toArray(),
                $catalog->listManufacturers($this->actor($request)),
            );

            return new JsonResponse(['items' => $items]);
        } catch (DBALException) {
            return $this->storageError($request);
        }
    }

    #[OA\Get(
        path: '/api/v1/vehicles',
        summary: 'Search published vehicle variants',
        tags: ['Catalogue'],
    )]
    #[OA\Parameter(
        name: 'q',
        in: 'query',
        description: 'Search variant, model or manufacturer name',
        schema: new OA\Schema(type: 'string', maxLength: 200),
    )]
    #[OA\Parameter(name: 'manufacturerId', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'modelId', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'generationId', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'fuelType', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'bodyStyle', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'powertrain', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'transmission', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'minPowerKw', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'maxPowerKw', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'minSeats', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 20))]
    #[OA\Parameter(name: 'maxSeats', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 20))]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100),
    )]
    #[OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0))]
    #[OA\Response(
        response: 200,
        description: 'Paginated vehicle variants',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/VehicleResponse'),
                ),
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'limit', type: 'integer'),
                new OA\Property(property: 'offset', type: 'integer'),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Invalid search criteria')]
    #[RequiresScope('catalog:read')]
    #[Route('/vehicles', methods: ['GET'])]
    public function vehicles(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $criteria = VehicleSearchCriteria::fromRequest($request);
            $result = $catalog->listVehicles(
                $this->actor($request),
                $criteria->filters,
                $criteria->limit,
                $criteria->offset,
            );

            $items = array_map(
                static fn (array $item): array => VehicleResponse::fromArray($item)->toArray(),
                $result['items'],
            );

            return new JsonResponse([
                'items' => $items,
                'total' => $result['total'],
                'limit' => $criteria->limit,
                'offset' => $criteria->offset,
            ]);
        } catch (InvalidPaginationException $exception) {
            return $this->problem(400, 'INVALID_PAGINATION', $exception->getMessage(), $request);
        } catch (InvalidArgumentException $exception) {
            return $this->problem(400, 'INVALID_FILTER', $exception->getMessage(), $request);
        } catch (DBALException) {
            return $this->storageError($request);
        }
    }

    #[OA\Get(
        path: '/api/v1/vehicles/{variantId}',
        summary: 'Get a published vehicle variant',
        tags: ['Catalogue'],
    )]
    #[OA\Parameter(
        name: 'variantId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', pattern: '^[a-f0-9]{32}$'),
    )]
    #[OA\Response(
        response: 200,
        description: 'Vehicle details',
        content: new OA\JsonContent(ref: '#/components/schemas/VehicleResponse'),
    )]
    #[OA\Response(response: 404, description: 'Vehicle not found')]
    #[RequiresScope('catalog:read')]
    #[Route('/vehicles/{variantId}', methods: ['GET'])]
    public function vehicle(
        string $variantId,
        Request $request,
        CatalogApplicationService $catalog,
    ): JsonResponse {
        if (preg_match('/^[a-f0-9]{32}$/D', $variantId) !== 1) {
            return $this->vehicleNotFound($request);
        }

        try {
            $vehicle = $catalog->getVehicle($this->actor($request), $variantId);

            if ($vehicle === null) {
                return $this->vehicleNotFound($request);
            }

            return new JsonResponse(VehicleResponse::fromArray($vehicle)->toArray());
        } catch (DBALException) {
            return $this->storageError($request);
        }
    }

    private function actor(Request $request): CatalogActor
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new UnauthorizedHttpException('Bearer', 'An authenticated catalogue principal is required.');
        }

        return new CatalogActor($principal->subject, $principal->scopes);
    }

    private function vehicleNotFound(Request $request): JsonResponse
    {
        return $this->problem(404, 'VEHICLE_NOT_FOUND', 'The requested vehicle does not exist.', $request);
    }

    private function storageError(Request $request): JsonResponse
    {
        return $this->problem(
            500,
            'CATALOGUE_STORAGE_ERROR',
            'The catalogue operation could not be completed.',
            $request,
        );
    }

    private function invalidJson(Request $request): JsonResponse
    {
        return $this->problem(400, 'INVALID_JSON', 'Request body must contain valid JSON.', $request);
    }

    private function problem(
        int $status,
        string $code,
        string $detail,
        Request $request,
    ): JsonResponse {
        $title = match ($status) {
            400 => 'Bad Request',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Unprocessable Content',
            default => 'Internal Server Error',
        };

        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'errorCode' => $code,
                'instance' => $request->getPathInfo(),
            ],
            $status,
            [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
