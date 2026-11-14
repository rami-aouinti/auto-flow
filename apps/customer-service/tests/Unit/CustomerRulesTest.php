<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Presentation\Http\Controller\CustomerController;
use App\Presentation\Http\Controller\CustomerPortalController;
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
