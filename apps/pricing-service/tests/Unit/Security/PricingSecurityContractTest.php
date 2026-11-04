<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Controller\PricingController;
use App\Controller\PricingQuoteController;
use AutoFlow\CoreBundle\Security\RequiresScope;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PricingSecurityContractTest extends TestCase
{
    public function testEveryQuoteRestOperationRequiresThePricingScope(): void
    {
        $operations = [
            [PricingController::class, 'calculate'],
            [PricingQuoteController::class, 'getQuote'],
            [PricingQuoteController::class, 'listQuotes'],
            [PricingQuoteController::class, 'deleteQuote'],
        ];

        foreach ($operations as [$controller, $method]) {
            $attributes = (new ReflectionMethod($controller, $method))->getAttributes(RequiresScope::class);
            self::assertCount(1, $attributes, sprintf('%s::%s must declare RequiresScope.', $controller, $method));
            self::assertSame('pricing:quote', $attributes[0]->newInstance()->scope);
        }
    }
}
