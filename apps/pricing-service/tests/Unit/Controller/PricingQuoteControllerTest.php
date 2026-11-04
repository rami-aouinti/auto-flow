<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Application\GetPricingQuote\GetPricingQuoteHandler;
use App\Application\Port\Out\PricingQuoteRepositoryInterface;
use App\Controller\PricingQuoteController;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class PricingQuoteControllerTest extends TestCase
{
    private const string QUOTE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testQuoteReadIsRestrictedToTheAuthenticatedOwnerByDefault(): void
    {
        $repository = $this->createMock(PricingQuoteRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findById')
            ->with('tenant-demo', self::QUOTE_ID, 'user-demo')
            ->willReturn(null);
        $request = $this->request(['pricing:quote']);

        $response = (new PricingQuoteController())->getQuote(
            self::QUOTE_ID,
            $request,
            new GetPricingQuoteHandler($repository),
            $this->tenantContext($request),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReadAnyScopeRemovesTheOwnerFilterWithinTheTenant(): void
    {
        $repository = $this->createMock(PricingQuoteRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findById')
            ->with('tenant-demo', self::QUOTE_ID, null)
            ->willReturn(null);
        $request = $this->request(['pricing:quote', 'pricing:quote:read:any']);

        $response = (new PricingQuoteController())->getQuote(
            self::QUOTE_ID,
            $request,
            new GetPricingQuoteHandler($repository),
            $this->tenantContext($request),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /** @param list<string> $scopes */
    private function request(array $scopes): Request
    {
        $request = Request::create('/api/pricing/quotes/' . self::QUOTE_ID, 'GET');
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('user-demo', 'tenant-demo', $scopes),
        );
        return $request;
    }

    private function tenantContext(Request $request): TenantContext
    {
        $stack = new RequestStack();
        $stack->push($request);
        return new TenantContext($stack);
    }
}
