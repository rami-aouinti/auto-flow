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
final readonly class CatalogManagementController
{
    #[OA\Post(
        path: '/api/v1/manufacturers',
        summary: 'Register a manufacturer',
        tags: ['Catalogue management'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'VOLKSWAGEN'),
                new OA\Property(property: 'name', type: 'string', example: 'Volkswagen'),
            ],
            required: ['code', 'name'],
        ),
    )]
    #[OA\Response(response: 201, description: 'Manufacturer registered')]
    #[OA\Response(response: 409, description: 'Manufacturer code already exists')]
    #[RequiresScope('catalog:manage')]
    #[Route('/manufacturers', methods: ['POST'])]
    public function createManufacturer(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $input = CreateManufacturerRequest::fromArray($request->toArray());
            $id = $catalog->registerManufacturer($this->actor($request), $input->code, $input->name);

            return new JsonResponse(['manufacturerId' => $id, 'status' => 'draft'], 201);
        } catch (InvalidArgumentException $exception) {
            return $this->problem(422, 'INVALID_MANUFACTURER', $exception->getMessage(), $request);
        } catch (UniqueConstraintViolationException) {
            return $this->catalogueConflict($request);
        } catch (DBALException) {
            return $this->storageError($request);
        } catch (JsonException) {
            return $this->invalidJson($request);
        }
    }

    #[OA\Post(path: '/api/v1/models', summary: 'Register a model', tags: ['Catalogue management'])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'manufacturerId', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
            ],
            required: ['manufacturerId', 'name'],
        ),
    )]
    #[OA\Response(response: 201, description: 'Model registered')]
    #[RequiresScope('catalog:manage')]
    #[Route('/models', methods: ['POST'])]
    public function createModel(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $input = CreateModelRequest::fromArray($request->toArray());
            $id = $catalog->registerModel($this->actor($request), $input->manufacturerId, $input->name);

            return new JsonResponse(['modelId' => $id, 'status' => 'draft'], 201);
        } catch (CatalogParentNotFoundException) {
            return $this->unknownParent($request);
        } catch (InvalidArgumentException $exception) {
            return $this->problem(422, 'INVALID_MODEL', $exception->getMessage(), $request);
        } catch (UniqueConstraintViolationException) {
            return $this->catalogueConflict($request);
        } catch (ForeignKeyConstraintViolationException) {
            return $this->unknownParent($request);
        } catch (DBALException) {
            return $this->storageError($request);
        } catch (JsonException) {
            return $this->invalidJson($request);
        }
    }

    #[OA\Post(path: '/api/v1/generations', summary: 'Register a model generation', tags: ['Catalogue management'])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'modelId', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'productionStartYear', type: 'integer', nullable: true),
                new OA\Property(property: 'productionEndYear', type: 'integer', nullable: true),
            ],
            required: ['modelId', 'name'],
        ),
    )]
    #[OA\Response(response: 201, description: 'Generation registered')]
    #[RequiresScope('catalog:manage')]
    #[Route('/generations', methods: ['POST'])]
    public function createGeneration(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $input = CreateGenerationRequest::fromArray($request->toArray());
            $id = $catalog->registerGeneration(
                $this->actor($request),
                $input->modelId,
                $input->name,
                $input->productionStartYear,
                $input->productionEndYear,
            );

            return new JsonResponse(['generationId' => $id, 'status' => 'draft'], 201);
        } catch (CatalogParentNotFoundException) {
            return $this->unknownParent($request);
        } catch (InvalidArgumentException $exception) {
            return $this->problem(422, 'INVALID_GENERATION', $exception->getMessage(), $request);
        } catch (UniqueConstraintViolationException) {
            return $this->catalogueConflict($request);
        } catch (ForeignKeyConstraintViolationException) {
            return $this->unknownParent($request);
        } catch (DBALException) {
            return $this->storageError($request);
        } catch (JsonException) {
            return $this->invalidJson($request);
        }
    }

    #[OA\Post(
        path: '/api/v1/vehicles',
        summary: 'Register a technical vehicle variant',
        tags: ['Catalogue management'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'generationId', type: 'string'),
                new OA\Property(property: 'manufacturerCode', type: 'string', nullable: true),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(
                    property: 'fuelType',
                    type: 'string',
                    enum: [
                        'petrol',
                        'diesel',
                        'electric',
                        'hybrid',
                        'plug_in_hybrid',
                        'hydrogen',
                        'lpg',
                        'cng',
                        'other',
                    ],
                ),
                new OA\Property(
                    property: 'powertrain',
                    type: 'string',
                    enum: ['ice', 'mhev', 'hev', 'phev', 'bev', 'fcev', 'other'],
                ),
                new OA\Property(
                    property: 'bodyStyle',
                    type: 'string',
                    enum: ['suv', 'sedan', 'hatchback', 'estate', 'coupe', 'convertible', 'van', 'pickup', 'other'],
                ),
                new OA\Property(
                    property: 'transmission',
                    type: 'string',
                    enum: ['manual', 'automatic', 'single_speed', 'other'],
                ),
                new OA\Property(property: 'powerKw', type: 'integer', nullable: true),
                new OA\Property(property: 'displacementCc', type: 'integer', nullable: true),
                new OA\Property(property: 'seats', type: 'integer', nullable: true),
                new OA\Property(property: 'doors', type: 'integer', nullable: true),
                new OA\Property(property: 'curbWeightKg', type: 'integer', nullable: true),
                new OA\Property(property: 'lengthMm', type: 'integer', nullable: true),
                new OA\Property(property: 'widthMm', type: 'integer', nullable: true),
                new OA\Property(property: 'heightMm', type: 'integer', nullable: true),
                new OA\Property(property: 'co2EmissionGPerKm', type: 'integer', nullable: true),
                new OA\Property(
                    property: 'mediaReferences',
                    type: 'array',
                    items: new OA\Items(type: 'object'),
                    nullable: true,
                ),
            ],
            required: ['generationId', 'name', 'fuelType', 'powertrain', 'bodyStyle', 'transmission'],
        ),
    )]
    #[OA\Response(response: 201, description: 'Variant registered in draft state')]
    #[RequiresScope('catalog:manage')]
    #[Route('/vehicles', methods: ['POST'])]
    public function createVariant(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $input = CreateVehicleVariantRequest::fromArray($request->toArray());
            $id = $catalog->registerVariant($this->actor($request), $input->toArray());

            return new JsonResponse(['variantId' => $id, 'status' => 'draft'], 201);
        } catch (CatalogParentNotFoundException) {
            return $this->unknownParent($request);
        } catch (InvalidArgumentException $exception) {
            return $this->problem(422, 'INVALID_VARIANT', $exception->getMessage(), $request);
        } catch (UniqueConstraintViolationException) {
            return $this->catalogueConflict($request);
        } catch (ForeignKeyConstraintViolationException) {
            return $this->unknownParent($request);
        } catch (DBALException) {
            return $this->storageError($request);
        } catch (JsonException) {
            return $this->invalidJson($request);
        }
    }

    #[OA\Post(
        path: '/api/v1/vehicles/{variantId}/publish',
        summary: 'Publish a draft vehicle variant',
        tags: ['Catalogue management'],
    )]
    #[OA\Parameter(
        name: 'variantId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', pattern: '^[a-f0-9]{32}$'),
    )]
    #[OA\Response(response: 200, description: 'Vehicle variant published')]
    #[OA\Response(response: 404, description: 'Vehicle variant not found or cannot be published')]
    #[RequiresScope('catalog:manage')]
    #[Route('/vehicles/{variantId}/publish', methods: ['POST'])]
    public function publishVariant(
        string $variantId,
        Request $request,
        CatalogApplicationService $catalog,
    ): JsonResponse {
        if (preg_match('/^[a-f0-9]{32}$/D', $variantId) !== 1) {
            return $this->vehicleNotFound($request);
        }

        try {
            if (!$catalog->publishVariant($this->actor($request), $variantId)) {
                return $this->vehicleNotFound($request);
            }

            return new JsonResponse(['variantId' => $variantId, 'status' => 'published']);
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

    private function catalogueConflict(Request $request): JsonResponse
    {
        return $this->problem(
            409,
            'CATALOGUE_CONFLICT',
            'A catalogue entry with the same canonical code or name already exists.',
            $request,
        );
    }

    private function unknownParent(Request $request): JsonResponse
    {
        return $this->problem(422, 'UNKNOWN_PARENT', 'The referenced catalogue parent does not exist.', $request);
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
