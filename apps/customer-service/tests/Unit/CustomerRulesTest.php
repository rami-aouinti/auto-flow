<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Presentation\Http\Controller\CustomerController;
use App\Presentation\Http\Controller\CustomerPortalController;
use App\Presentation\Http\Controller\MercureAccessController;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class CustomerRulesTest extends TestCase
{
    private function call(string $name, array $args): mixed
    {
        $controller = (new ReflectionClass(CustomerController::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(CustomerController::class, $name))->invokeArgs($controller, $args);
    }

    public function testIndividualRequiresBothNames(): void
    {
        $this->expectException(\DomainException::class);
        $this->call('customerInput', [['customerType' => 'individual', 'firstName' => 'Ada']]);
    }

    public function testCompanyRequiresLegalName(): void
    {
        $this->expectException(\DomainException::class);
        $this->call('customerInput', [['customerType' => 'company']]);
    }

    public function testEmailIsNormalizedAndLocaleDefaultsToGerman(): void
    {
        $value = $this->call('customerInput', [[
            'customerType' => 'individual',
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => 'Ada@Example.Test',
        ]]);

        self::assertSame('ada@example.test', $value['email']);
        self::assertSame('de-DE', $value['locale']);
    }

    public function testCountryCodeMustBeAlpha2(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->call('addressInput', [[
            'line1' => 'Street 1',
            'postalCode' => '10115',
            'city' => 'Berlin',
            'countryCode' => 'DEU',
        ]]);
    }

    public function testPortalAddressInputNormalizesCountryCodeAndAllowsOptionalFields(): void
    {
        $controller = (new ReflectionClass(CustomerPortalController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CustomerPortalController::class, 'addressInput');
        $value = $method->invoke($controller, [
            'addressType' => 'billing',
            'line1' => 'Main Street 1',
            'postalCode' => '10115',
            'city' => 'Berlin',
            'countryCode' => 'de',
            'isDefault' => true,
        ]);

        self::assertSame('DE', $value['countryCode']);
        self::assertNull($value['line2']);
        self::assertTrue($value['isDefault']);
    }

    public function testPortalAddressInputRejectsInvalidCountryCode(): void
    {
        $controller = (new ReflectionClass(CustomerPortalController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CustomerPortalController::class, 'addressInput');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($controller, [
            'line1' => 'Main Street 1',
            'postalCode' => '10115',
            'city' => 'Berlin',
            'countryCode' => 'DEU',
        ]);
    }

    public function testPortalAddressInputRequiresBooleanDefaultFlag(): void
    {
        $controller = (new ReflectionClass(CustomerPortalController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CustomerPortalController::class, 'addressInput');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($controller, [
            'line1' => 'Main Street 1',
            'postalCode' => '10115',
            'city' => 'Berlin',
            'countryCode' => 'DE',
            'isDefault' => 'true',
        ]);
    }
}

    public function testPortalRejectsPrincipalsWithoutPortalScope(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('transactional');
        $controller = new CustomerPortalController($db);
        $request = Request::create('/api/v1/customers/me');
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('user-1', 'tenant-a', ['customers:read']),
        );

        $response = $controller->profile($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('customers:portal', (string) $response->getContent());
    }

    public function testPortalRejectsMissingTenantContext(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('transactional');
        $controller = new CustomerPortalController($db);
        $request = Request::create('/api/v1/customers/me');
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('user-1', null, ['customers:portal']),
        );

        $response = $controller->profile($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Tenant context is missing', (string) $response->getContent());
    }

    public function testMercureAccessIssuesOnlyTenantScopedHttpOnlyCookie(): void
    {
        $previousKey = getenv('MERCURE_SUBSCRIBER_JWT_KEY');
        $previousBase = getenv('AUTOFLOW_MERCURE_TOPIC_BASE');
        putenv('MERCURE_SUBSCRIBER_JWT_KEY=' . str_repeat('subscriber-key-', 4));
        putenv('AUTOFLOW_MERCURE_TOPIC_BASE=https://events.autoflow.test/tenants');

        try {
            $request = Request::create('https://api.autoflow.test/api/v1/realtime/access');
            $request->attributes->set(
                AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
                new AuthenticatedPrincipal('user-1', 'tenant-a', ['customers:read']),
            );

            $response = (new MercureAccessController())->access($request);
            $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $cookies = $response->headers->getCookies();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('https://events.autoflow.test/tenants/tenant-a/events', $payload['topic']);
            self::assertCount(1, $cookies);
            self::assertSame('mercureAuthorization', $cookies[0]->getName());
            self::assertTrue($cookies[0]->isHttpOnly());
            self::assertTrue($cookies[0]->isSecure());

            $segments = explode('.', $cookies[0]->getValue());
            self::assertCount(3, $segments);
            $jwtPayload = json_decode(
                base64_decode(strtr($segments[1], '-_', '+/') . str_repeat('=', (4 - strlen($segments[1]) % 4) % 4)),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame([$payload['topic']], $jwtPayload['mercure']['subscribe']);
        } finally {
            putenv('MERCURE_SUBSCRIBER_JWT_KEY' . ($previousKey === false ? '' : '=' . $previousKey));
            putenv('AUTOFLOW_MERCURE_TOPIC_BASE' . ($previousBase === false ? '' : '=' . $previousBase));
        }
    }

    public function testMercureAccessRejectsUsersWithoutCustomerReadScope(): void
    {
        putenv('MERCURE_SUBSCRIBER_JWT_KEY=' . str_repeat('subscriber-key-', 4));
        $request = Request::create('https://api.autoflow.test/api/v1/realtime/access');
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('user-1', 'tenant-a', ['customers:portal']),
        );

        $response = (new MercureAccessController())->access($request);

        self::assertSame(403, $response->getStatusCode());
    }
