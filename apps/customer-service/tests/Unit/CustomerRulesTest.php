<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use App\Presentation\Http\Controller\CustomerController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
final class CustomerRulesTest extends TestCase
{
 private function call(string $name,array $args):mixed{$o=(new ReflectionClass(CustomerController::class))->newInstanceWithoutConstructor();return (new ReflectionMethod(CustomerController::class,$name))->invokeArgs($o,$args);}
 public function testIndividualRequiresBothNames():void{$this->expectException(\DomainException::class);$this->call('customerInput',[['customerType'=>'individual','firstName'=>'Ada']]);}
 public function testCompanyRequiresLegalName():void{$this->expectException(\DomainException::class);$this->call('customerInput',[['customerType'=>'company']]);}
 public function testEmailIsNormalizedAndLocaleDefaultsToGerman():void{$v=$this->call('customerInput',[['customerType'=>'individual','firstName'=>'Ada','lastName'=>'Lovelace','email'=>'Ada@Example.Test']]);self::assertSame('ada@example.test',$v['email']);self::assertSame('de-DE',$v['locale']);}
 public function testCountryCodeMustBeAlpha2():void{$this->expectException(\InvalidArgumentException::class);$this->call('addressInput',[['line1'=>'Street 1','postalCode'=>'10115','city'=>'Berlin','countryCode'=>'DEU']]);}
}
