<?php

declare(strict_types=1);

namespace App\\Presentation\\Http\\Controller;

use App\\Application\\CatalogApplicationService;
use AutoFlow\\CoreBundle\\Security\\RequiresScope;
use InvalidArgumentException;
use PDOException;
use Symfony\\Component\\HttpFoundation\\JsonResponse;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\Routing\\Attribute\\Route;
use OpenApi\\Attributes as OA;

#[Route('/api/v1')]
final readonly class CatalogController
{
    #[OA\\Get(path: '/api/v1/manufacturers', summary: 'List published manufacturers', tags: ['Catalogue'])]
    #[OA\\Response(response: 200, description: 'Published manufacturers')]
    #[RequiresScope('catalog:read')]
    #[Route('/manufacturers', methods: ['GET'])]
    public function manufacturers(CatalogApplicationService $catalog): JsonResponse
    {
        return new JsonResponse(['items'=>$catalog->listManufacturers()]);
    }

    #[OA\\Get(path: '/api/v1/vehicles', summary: 'Search published vehicle variants', tags: ['Catalogue'])]
    #[OA\\Parameter(name: 'q', in: 'query', description: 'Search variant, model or manufacturer name', schema: new OA\\Schema(type: 'string'))]
    #[OA\\Parameter(name: 'manufacturerId', in: 'query', schema: new OA\\Schema(type: 'string'))]
    #[OA\\Parameter(name: 'fuelType', in: 'query', schema: new OA\\Schema(type: 'string'))]
    #[OA\\Parameter(name: 'bodyStyle', in: 'query', schema: new OA\\Schema(type: 'string'))]
    #[OA\\Parameter(name: 'powertrain', in: 'query', schema: new OA\\Schema(type: 'string'))]
    #[OA\\Parameter(name: 'transmission', in: 'query', schema: new OA\\Schema(type: 'string'))]
    #[OA\\Parameter(name: 'minPowerKw', in: 'query', schema: new OA\\Schema(type: 'integer', minimum: 1))]
    #[OA\\Parameter(name: 'maxPowerKw', in: 'query', schema: new OA\\Schema(type: 'integer', minimum: 1))]
    #[OA\\Parameter(name: 'minSeats', in: 'query', schema: new OA\\Schema(type: 'integer', minimum: 1))]
    #[OA\\Parameter(name: 'maxSeats', in: 'query', schema: new OA\\Schema(type: 'integer', minimum: 1))]
    #[OA\\Parameter(name: 'limit', in: 'query', schema: new OA\\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100))]
    #[OA\\Parameter(name: 'offset', in: 'query', schema: new OA\\Schema(type: 'integer', default: 0, minimum: 0))]
    #[OA\\Response(response: 200, description: 'Paginated vehicle variants')]
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
        try {
            $result=$catalog->listVehicles($filters,$limit,$offset);
            return new JsonResponse(['items'=>$result['items'],'total'=>$result['total'],'limit'=>$limit,'offset'=>$offset]);
        } catch (InvalidArgumentException $e) {
            return $this->problem(400,'INVALID_FILTER',$e->getMessage(),$request);
        }
    }

    #[OA\\Get(path: '/api/v1/vehicles/{variantId}', summary: 'Get a published vehicle variant', tags: ['Catalogue'])]
    #[OA\\Parameter(name: 'variantId', in: 'path', required: true, schema: new OA\\Schema(type: 'string', pattern: '^[a-f0-9]{32}$'))]
    #[OA\\Response(response: 200, description: 'Vehicle details')]
    #[OA\\Response(response: 404, description: 'Vehicle not found')]
    #[RequiresScope('catalog:read')]
    #[Route('/vehicles/{variantId}', methods: ['GET'])]
    public function vehicle(string $variantId, Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        if (preg_match('/^[a-f0-9]{32}$/D',$variantId)!==1) {
            return $this->problem(404,'VEHICLE_NOT_FOUND','The requested vehicle does not exist.',$request);
        }
        $vehicle=$catalog->getVehicle($variantId);
        return $vehicle===null
            ? $this->problem(404,'VEHICLE_NOT_FOUND','The requested vehicle does not exist.',$request)
            : new JsonResponse($vehicle);
    }

    #[OA\\Post(path: '/api/v1/manufacturers', summary: 'Register a manufacturer', tags: ['Catalogue management'])]
    #[OA\\RequestBody(required: true, content: new OA\\JsonContent(properties: [
        new OA\\Property(property: 'code',type: 'string',example: 'VOLKSWAGEN'),
        new OA\\Property(property: 'name',type: 'string',example: 'Volkswagen')
    ], required: ['code','name']))]
    #[OA\\Response(response: 201, description: 'Manufacturer registered')]
    #[RequiresScope('catalog:manage')]
    #[Route('/manufacturers', methods: ['POST'])]
    public function createManufacturer(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $data=$request->toArray();
            $id=$catalog->registerManufacturer((string)($data['code']??''),(string)($data['name']??''));
            return new JsonResponse(['manufacturerId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) {
            return $this->problem(422,'INVALID_MANUFACTURER',$e->getMessage(),$request);
        } catch (PDOException $e) {
            return $this->databaseProblem($e,$request);
        } catch (\\JsonException) {
            return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request);
        }
    }

    #[OA\\Post(path: '/api/v1/models', summary: 'Register a model', tags: ['Catalogue management'])]
    #[OA\\RequestBody(required: true, content: new OA\\JsonContent(properties: [
        new OA\\Property(property: 'manufacturerId',type: 'string'),
        new OA\\Property(property: 'name',type: 'string')
    ], required: ['manufacturerId','name']))]
    #[RequiresScope('catalog:manage')]
    #[Route('/models', methods: ['POST'])]
    public function createModel(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $data=$request->toArray();
            $id=$catalog->registerModel((string)($data['manufacturerId']??''),(string)($data['name']??''));
            return new JsonResponse(['modelId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) { return $this->problem(422,'INVALID_MODEL',$e->getMessage(),$request); }
        catch (PDOException $e) { return $this->databaseProblem($e,$request); }
        catch (\\JsonException) { return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request); }
    }

    #[OA\\Post(path: '/api/v1/generations', summary: 'Register a model generation', tags: ['Catalogue management'])]
    #[OA\\RequestBody(required: true, content: new OA\\JsonContent(properties: [
        new OA\\Property(property: 'modelId',type: 'string'),new OA\\Property(property: 'name',type: 'string'),
        new OA\\Property(property: 'productionStartYear',type: 'integer',nullable: true),
        new OA\\Property(property: 'productionEndYear',type: 'integer',nullable: true)
    ], required: ['modelId','name']))]
    #[RequiresScope('catalog:manage')]
    #[Route('/generations', methods: ['POST'])]
    public function createGeneration(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $data=$request->toArray();
            $id=$catalog->registerGeneration((string)($data['modelId']??''),(string)($data['name']??''),
                isset($data['productionStartYear'])?(int)$data['productionStartYear']:null,
                isset($data['productionEndYear'])?(int)$data['productionEndYear']:null);
            return new JsonResponse(['generationId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) { return $this->problem(422,'INVALID_GENERATION',$e->getMessage(),$request); }
        catch (PDOException $e) { return $this->databaseProblem($e,$request); }
        catch (\\JsonException) { return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request); }
    }

    #[OA\\Post(path: '/api/v1/vehicles', summary: 'Register a technical vehicle variant', tags: ['Catalogue management'])]
    #[OA\\RequestBody(required: true, content: new OA\\JsonContent(type: 'object', required: ['generationId','name','fuelType','powertrain','bodyStyle','transmission']))]
    #[OA\\Response(response: 201, description: 'Variant registered in draft state')]
    #[RequiresScope('catalog:manage')]
    #[Route('/vehicles', methods: ['POST'])]
    public function createVariant(Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        try {
            $id=$catalog->registerVariant($request->toArray());
            return new JsonResponse(['variantId'=>$id,'status'=>'draft'],201);
        } catch (InvalidArgumentException $e) { return $this->problem(422,'INVALID_VARIANT',$e->getMessage(),$request); }
        catch (PDOException $e) { return $this->databaseProblem($e,$request); }
        catch (\\JsonException) { return $this->problem(400,'INVALID_JSON','Request body must contain valid JSON.',$request); }
    }

    #[RequiresScope('catalog:manage')]
    #[Route('/vehicles/{variantId}/publish', methods: ['POST'])]
    public function publishVariant(string $variantId, Request $request, CatalogApplicationService $catalog): JsonResponse
    {
        if (!$catalog->publishVariant($variantId)) {
            return $this->problem(404,'VEHICLE_NOT_FOUND','The requested vehicle does not exist.',$request);
        }
        return new JsonResponse(['variantId'=>$variantId,'status'=>'published']);
    }

    private function databaseProblem(PDOException $e, Request $request): JsonResponse
    {
        $state=(string)($e->errorInfo[0]??$e->getCode());
        if ($state==='23505') return $this->problem(409,'CATALOGUE_CONFLICT','A catalogue entry with the same canonical code or name already exists.',$request);
        if ($state==='23503') return $this->problem(422,'UNKNOWN_PARENT','The referenced catalogue parent does not exist.',$request);
        return $this->problem(500,'CATALOGUE_STORAGE_ERROR','The catalogue operation could not be completed.',$request);
    }

    private function problem(int $status,string $code,string $detail,Request $request): JsonResponse
    {
        return new JsonResponse(['type'=>'about:blank','title'=>match($status){400=>'Bad Request',404=>'Not Found',409=>'Conflict',422=>'Unprocessable Content',default=>'Internal Server Error'},
            'status'=>$status,'detail'=>$detail,'errorCode'=>$code,'instance'=>$request->getPathInfo()],
            $status,['Content-Type'=>'application/problem+json','Cache-Control'=>'no-store']);
    }
}