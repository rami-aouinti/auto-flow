<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\CatalogApplicationService;
use App\Application\Security\CatalogActor;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\RequiresScope;
use InvalidArgumentException;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use OpenApi\Attributes as OA;

#[Route('/api/v1')]
final readonly class CatalogController
{
    #[OA\Get(path: '/api/v1/manufacturers', summary: 'List published manufacturers', tags: ['Catalogue'])]
    #[OA\Response(response: 200, description: 'Published manufacturers')]
    #[RequiresScope('catalog:read')]
    #[Route('/manufacturers', methods: ['GET'])]
    public function manufacturers(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        return new JsonResponse(['items'=>$catalog->listManufacturers($this->actor($request))]);
    }

    #[OA\Get(path: '/api/v1/vehicles', summary: 'Search published vehicle variants', tags: ['Catalogue'])]
    #[OA\Parameter(name: 'q', in: 'query', description: 'Search variant, model or manufacturer name', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'manufacturerId', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'modelId', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'generationId', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'fuelType', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'bodyStyle', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'powertrain', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'transmission', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'minPowerKw', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'maxPowerKw', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'minSeats', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'maxSeats', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100))]
    #[OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0))]
    #[OA\Response(response: 200, description: 'Paginated vehicle variants')]
    #[RequiresScope('catalog:read')]
    #[Route('/vehicles', methods: ['GET'])]
    public function vehicles(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        $limit=filter_var($request->query->get('limit','20'),FILTER_VALIDATE_INT);
        $offset=filter_var($request->query->get('offset','0'),FILTER_VALIDATE_INT);
        if (!is_int($limit)||!is_int($offset)||$limit<1||$limit>100||$offset<0) {
            return $this->problem(400,'INVALID_PAGINATION','limit must be 1-100 and offset must be non-negative.',$request);
        }
        $filters=[];
        foreach (['q','manufacturerId','modelId','generationId','fuelType','powertrain','bodyStyle','transmission','minPowerKw','maxPowerKw','minSeats','maxSeats'] as $key) {
            $value=$request->query->get($key);
            if ($value!==null && $value!=='') { $filters[$key]=$value; }
        }
        foreach (['manufacturerId','modelId','generationId'] as $idKey) {
            if (isset($filters[$idKey]) && preg_match('/^[a-f0-9]{32}$/D', (string) $filters[$idKey]) !== 1) {
                return $this->problem(400,'INVALID_FILTER',sprintf('%s must be a valid catalogue identifier.',$idKey),$request);
            }
        }
        foreach (['minPowerKw','maxPowerKw','minSeats','maxSeats'] as $numericKey) {
            if (isset($filters[$numericKey]) && filter_var($filters[$numericKey],FILTER_VALIDATE_INT) === false) {
                return $this->problem(400,'INVALID_FILTER',sprintf('%s must be an integer.',$numericKey),$request);
            }
        }
        foreach ([
            'fuelType'=>['petrol','diesel','electric','hybrid','plug_in_hybrid','hydrogen','lpg','cng','other'],
            'powertrain'=>['ice','mhev','hev','phev','bev','fcev','other'],
            'bodyStyle'=>['suv','sedan','hatchback','estate','coupe','convertible','van','pickup','other'],
            'transmission'=>['manual','automatic','single_speed','other']
        ] as $key=>$allowed) {
            if (isset($filters[$key]) && !in_array($filters[$key],$allowed,true)) {
                return $this->problem(400,'INVALID_FILTER',sprintf('%s is not a supported catalogue filter.',$key),$request);
            }
        }
        if (isset($filters['minPowerKw'],$filters['maxPowerKw']) && (int)$filters['minPowerKw']>(int)$filters['maxPowerKw']) {
            return $this->problem(400,'INVALID_FILTER','minPowerKw cannot exceed maxPowerKw.',$request);
        }
        if (isset($filters['minSeats'],$filters['maxSeats']) && (int)$filters['minSeats']>(int)$filters['maxSeats']) {
            return $this->problem(400,'INVALID_FILTER','minSeats cannot exceed maxSeats.',$request);
        }
        try {
            $result=$catalog->listVehicles($this->actor($request), $filters, $limit, $offset);
            return new JsonResponse(['items'=>$result['items'],'total'=>$result['total'],'limit'=>$limit,'offset'=>$offset]);
        } catch (InvalidArgumentException $e) {
            return $this->problem(400,'INVALID_FILTER',$e->getMessage(),$request);
        }
    }

    #[OA\Get(path: '/api/v1/vehicles/{variantId}', summary: 'Get a published vehicle variant', tags: ['Catalogue'])]
    #[OA\Parameter(name: 'variantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[a-f0-9]{32}$'))]
    #[OA\Response(response: 200, description: 'Vehicle details')]
    #[OA\Response(response: 404, description: 'Vehicle not found')]
    #[RequiresScope('catalog:read')]
    #[Route('/vehicles/{variantId}', methods: ['GET'])]
    public function vehicle(string $variantId, Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        if (preg_match('/^[a-f0-9]{32}$/D',$variantId)!==1) {
            return $this->problem(404,'VEHICLE_NOT_FOUND','The requested vehicle does not exist.',$request);
        }
        $vehicle=$catalog->getVehicle($this->actor($request), $variantId);
        return $vehicle===null
            ? $this->problem(404,'VEHICLE_NOT_FOUND','The requested vehicle does not exist.',$request)
            : new JsonResponse($vehicle);
    }

    #[OA\Post(path: '/api/v1/manufacturers', summary: 'Register a manufacturer', tags: ['Catalogue management'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'code',type: 'string',example: 'VOLKSWAGEN'),
        new OA\Property(property: 'name',type: 'string',example: 'Volkswagen')
    ], required: ['code','name']))]
    #[OA\Response(response: 201, description: 'Manufacturer registered')]
    #[RequiresScope('catalog:manage')]
    #[Route('/manufacturers', methods: ['POST'])]
    public function createManufacturer(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $data=$request->toArray();
            $id=$catalog->registerManufacturer($this->actor($request), (string) ($data['code'] ?? ''), (string) ($data['name'] ?? ''));
            return new JsonResponse(['manufacturerId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422,'INVALID_MANUFACTURER',$e->getMessage(),$request);
        } catch (UniqueConstraintViolationException) {
            return $this->problem(409, 'CATALOGUE_CONFLICT', 'A catalogue entry with the same canonical code or name already exists.', $request);
        } catch (ForeignKeyConstraintViolationException) {
            return $this->problem(422, 'UNKNOWN_PARENT', 'The referenced catalogue parent does not exist.', $request);
        } catch (DBALException) {
            return $this->problem(500, 'CATALOGUE_STORAGE_ERROR', 'The catalogue operation could not be completed.', $request);
        } catch (JsonException) {
            return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request);
        }
    }

    #[OA\Post(path: '/api/v1/models', summary: 'Register a model', tags: ['Catalogue management'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'manufacturerId',type: 'string'),
        new OA\Property(property: 'name',type: 'string')
    ], required: ['manufacturerId','name']))]
    #[RequiresScope('catalog:manage')]
    #[Route('/models', methods: ['POST'])]
    public function createModel(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $data=$request->toArray();
            $id=$catalog->registerModel($this->actor($request), (string) ($data['manufacturerId'] ?? ''), (string) ($data['name'] ?? ''));
            return new JsonResponse(['modelId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) { return $this->problem(422,'INVALID_MODEL',$e->getMessage(),$request); }
        catch (UniqueConstraintViolationException) { return $this->problem(409, 'CATALOGUE_CONFLICT', 'A catalogue entry with the same canonical code or name already exists.', $request); }
        catch (ForeignKeyConstraintViolationException) { return $this->problem(422, 'UNKNOWN_PARENT', 'The referenced catalogue parent does not exist.', $request); }
        catch (DBALException) { return $this->problem(500, 'CATALOGUE_STORAGE_ERROR', 'The catalogue operation could not be completed.', $request); }
        catch (JsonException) { return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request); }
    }

    #[OA\Post(path: '/api/v1/generations', summary: 'Register a model generation', tags: ['Catalogue management'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'modelId',type: 'string'),new OA\Property(property: 'name',type: 'string'),
        new OA\Property(property: 'productionStartYear',type: 'integer',nullable: true),
        new OA\Property(property: 'productionEndYear',type: 'integer',nullable: true)
    ], required: ['modelId','name']))]
    #[RequiresScope('catalog:manage')]
    #[Route('/generations', methods: ['POST'])]
    public function createGeneration(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $data=$request->toArray();
            $id=$catalog->registerGeneration($this->actor($request), (string) ($data['modelId'] ?? ''), (string) ($data['name'] ?? ''),
                isset($data['productionStartYear'])?(int)$data['productionStartYear']:null,
                isset($data['productionEndYear'])?(int)$data['productionEndYear']:null);
            return new JsonResponse(['generationId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) { return $this->problem(422,'INVALID_GENERATION',$e->getMessage(),$request); }
        catch (UniqueConstraintViolationException) { return $this->problem(409, 'CATALOGUE_CONFLICT', 'A catalogue entry with the same canonical code or name already exists.', $request); }
        catch (ForeignKeyConstraintViolationException) { return $this->problem(422, 'UNKNOWN_PARENT', 'The referenced catalogue parent does not exist.', $request); }
        catch (DBALException) { return $this->problem(500, 'CATALOGUE_STORAGE_ERROR', 'The catalogue operation could not be completed.', $request); }
        catch (JsonException) { return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request); }
    }

    #[OA\Post(path: '/api/v1/vehicles', summary: 'Register a technical vehicle variant', tags: ['Catalogue management'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'generationId', type: 'string'),
            new OA\Property(property: 'manufacturerCode', type: 'string', nullable: true),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'fuelType', type: 'string', enum: ['petrol', 'diesel', 'electric', 'hybrid', 'plug_in_hybrid', 'hydrogen', 'lpg', 'cng', 'other']),
            new OA\Property(property: 'powertrain', type: 'string', enum: ['ice', 'mhev', 'hev', 'phev', 'bev', 'fcev', 'other']),
            new OA\Property(property: 'bodyStyle', type: 'string', enum: ['suv', 'sedan', 'hatchback', 'estate', 'coupe', 'convertible', 'van', 'pickup', 'other']),
            new OA\Property(property: 'transmission', type: 'string', enum: ['manual', 'automatic', 'single_speed', 'other']),
            new OA\Property(property: 'powerKw', type: 'integer', nullable: true),
            new OA\Property(property: 'displacementCc', type: 'integer', nullable: true),
            new OA\Property(property: 'seats', type: 'integer', nullable: true),
            new OA\Property(property: 'doors', type: 'integer', nullable: true),
            new OA\Property(property: 'curbWeightKg', type: 'integer', nullable: true),
            new OA\Property(property: 'lengthMm', type: 'integer', nullable: true),
            new OA\Property(property: 'widthMm', type: 'integer', nullable: true),
            new OA\Property(property: 'heightMm', type: 'integer', nullable: true),
            new OA\Property(property: 'co2EmissionGPerKm', type: 'integer', nullable: true),
            new OA\Property(property: 'mediaReferences', type: 'array', items: new OA\Items(type: 'object'), nullable: true),
        ],
        required: ['generationId', 'name', 'fuelType', 'powertrain', 'bodyStyle', 'transmission']
    ))]
    #[OA\Response(response: 201, description: 'Variant registered in draft state')]
    #[RequiresScope('catalog:manage')]
    #[Route('/vehicles', methods: ['POST'])]
    public function createVariant(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $id=$catalog->registerVariant($this->actor($request), $request->toArray());
            return new JsonResponse(['variantId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) { return $this->problem(422,'INVALID_VARIANT',$e->getMessage(),$request); }
        catch (UniqueConstraintViolationException) { return $this->problem(409, 'CATALOGUE_CONFLICT', 'A catalogue entry with the same canonical code or name already exists.', $request); }
        catch (ForeignKeyConstraintViolationException) { return $this->problem(422, 'UNKNOWN_PARENT', 'The referenced catalogue parent does not exist.', $request); }
        catch (DBALException) { return $this->problem(500, 'CATALOGUE_STORAGE_ERROR', 'The catalogue operation could not be completed.', $request); }
        catch (JsonException) { return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request); }
    }

    #[OA\Post(path: '/api/v1/vehicles/{variantId}/publish', summary: 'Publish a draft vehicle variant', tags: ['Catalogue management'])]
    #[OA\Parameter(name: 'variantId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Vehicle variant published')]
    #[RequiresScope('catalog:manage')]
    #[Route('/vehicles/{variantId}/publish', methods: ['POST'])]
    public function publishVariant(string $variantId, Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        if (!$catalog->publishVariant($this->actor($request), $variantId)) {
            return $this->problem(404,'VEHICLE_NOT_FOUND','The requested vehicle does not exist.',$request);
        }
        return new JsonResponse(['variantId'=>$variantId,'status'=>'published']);
    }

    private function actor(Request $request): CatalogActor
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new AccessDeniedHttpException('An authenticated catalogue principal is required.');
        }

        return new CatalogActor($principal->subject, $principal->scopes);
    }

    private function problem(int $status,string $code,string $detail,Request $request): JsonResponse
    {
        return new JsonResponse(['type'=>'about:blank','title'=>match($status){400=>'Bad Request',404=>'Not Found',409=>'Conflict',422=>'Unprocessable Content',default=>'Internal Server Error'},
            'status'=>$status,'detail'=>$detail,'errorCode'=>$code,'instance'=>$request->getPathInfo()],
            $status,['Content-Type'=>'application/problem+json','Cache-Control'=>'no-store']);
    }
}