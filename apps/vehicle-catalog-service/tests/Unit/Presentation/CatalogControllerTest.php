<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation;

use App\Application\CatalogApplicationService;
use App\Application\Handler\GetVehicleHandler;
use App\Application\Handler\ListManufacturersHandler;
use App\Application\Handler\ListVehiclesHandler;
use App\Application\Handler\PublishVehicleVariantHandler;
use App\Application\Handler\RegisterGenerationHandler;
use App\Application\Handler\RegisterManufacturerHandler;
use App\Application\Handler\RegisterModelHandler;
use App\Application\Handler\RegisterVehicleVariantHandler;
use App\Application\Port\VehicleCatalogRepositoryInterface;
use App\Presentation\Http\Controller\CatalogController;
use App\Presentation\Http\Controller\CatalogManagementController;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class CatalogControllerTest extends TestCase
{
    public function testVehicleListReturnsPaginatedResponse(): void
    {
        $repository = $this->createMock(VehicleCatalogRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('listVehicles')
            ->with([], 20, 0)
            ->willReturn(['items' => [], 'total' => 0]);

        $request = Request::create('/api/v1/vehicles?limit=20&offset=0');
        $this->authenticate($request, ['catalog:read']);

        $response = (new CatalogController())->vehicles($request, $this->application($repository));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['items' => [], 'total' => 0, 'limit' => 20, 'offset' => 0],
            $this->responseData($response),
        );
    }

    public function testInvalidPaginationReturnsBadRequest(): void
    {
        $repository = $this->createMock(VehicleCatalogRepositoryInterface::class);
        $repository->expects(self::never())->method('listVehicles');

        $request = Request::create('/api/v1/vehicles?limit=0');

        $response = (new CatalogController())->vehicles($request, $this->application($repository));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('INVALID_PAGINATION', $this->responseData($response)['errorCode']);
    }

    public function testMissingVehicleReturnsNotFound(): void
    {
        $repository = $this->createMock(VehicleCatalogRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('getVehicle')
            ->with('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
            ->willReturn(null);

        $request = Request::create('/api/v1/vehicles/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->authenticate($request, ['catalog:read']);

        $response = (new CatalogController())->vehicle(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            $request,
            $this->application($repository),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('VEHICLE_NOT_FOUND', json_decode((string) $response->getContent(), true)['errorCode']);
    }

    public function testInvalidManufacturerInputReturnsUnprocessableContent(): void
    {
        $repository = $this->createMock(VehicleCatalogRepositoryInterface::class);
        $repository->expects(self::never())->method('createManufacturer');

        $request = Request::create(
            '/api/v1/manufacturers',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"code":"!","name":"Invalid"}',
        );
        $this->authenticate($request, ['catalog:manage']);

        $response = (new CatalogManagementController())->createManufacturer(
            $request,
            $this->application($repository),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('INVALID_MANUFACTURER', json_decode((string) $response->getContent(), true)['errorCode']);
    }

    public function testMissingAuthenticatedPrincipalIsUnauthorized(): void
    {
        $repository = $this->createMock(VehicleCatalogRepositoryInterface::class);
        $request = Request::create('/api/v1/manufacturers');

        $this->expectException(UnauthorizedHttpException::class);

        (new CatalogController())->manufacturers($request, $this->application($repository));
    }

    /** @return array<string, mixed> */
    private function responseData(JsonResponse $response): array
    {
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param list<string> $scopes */
    private function authenticate(Request $request, array $scopes): void
    {
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('catalog-test-actor', null, $scopes),
        );
    }

    private function application(
        VehicleCatalogRepositoryInterface $repository,
    ): CatalogApplicationService {
        return new CatalogApplicationService(
            new ListManufacturersHandler($repository),
            new ListVehiclesHandler($repository),
            new GetVehicleHandler($repository),
            new RegisterManufacturerHandler($repository),
            new RegisterModelHandler($repository),
            new RegisterGenerationHandler($repository),
            new RegisterVehicleVariantHandler($repository),
            new PublishVehicleVariantHandler($repository),
        );
    }
}
