<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Context;

use AutoFlow\CoreBundle\Context\MissingTenantContextException;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class TenantContextTest extends TestCase
{
    public function testItReturnsTenantFromAuthenticatedRequestPrincipal(): void
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('user-123', 'tenant-acme', ['offers:read']),
        );
        $requestStack->push($request);

        self::assertSame('tenant-acme', (new TenantContext($requestStack))->getTenantId());
    }

    public function testItReturnsNullWhenNoAuthenticatedTenantExists(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        self::assertNull((new TenantContext($requestStack))->getTenantId());
    }

    public function testItRestoresThePreviousTenantAfterScopedWork(): void
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('user-123', 'tenant-original', ['offers:read']),
        );
        $requestStack->push($request);
        $context = new TenantContext($requestStack);

        $result = $context->run('tenant-message', function () use ($context): string {
            return $context->requireTenantId();
        });

        self::assertSame('tenant-message', $result);
        self::assertSame('tenant-original', $context->requireTenantId());
    }

    public function testItRequiresTenantForTenantScopedOperations(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $this->expectException(MissingTenantContextException::class);

        (new TenantContext($requestStack))->requireTenantId();
    }
}
