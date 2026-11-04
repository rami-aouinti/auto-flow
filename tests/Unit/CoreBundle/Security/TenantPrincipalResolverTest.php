<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Security;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\TenantDelegationException;
use AutoFlow\CoreBundle\Security\TenantPrincipalResolver;
use PHPUnit\Framework\TestCase;

final class TenantPrincipalResolverTest extends TestCase
{
    public function testItKeepsTenantFromVerifiedUserToken(): void
    {
        $principal = new AuthenticatedPrincipal('user-1', 'tenant-a', ['offers:read']);
        $resolved = (new TenantPrincipalResolver())->resolve($principal, null);

        self::assertSame('tenant-a', $resolved->tenantId);
    }

    public function testItAllowsTenantDelegationOnlyWithExplicitScope(): void
    {
        $principal = new AuthenticatedPrincipal('service-account-offer', null, [
            'pricing:quote', TenantPrincipalResolver::DELEGATION_SCOPE,
        ]);
        $resolved = (new TenantPrincipalResolver())->resolve($principal, 'tenant-a');

        self::assertSame('tenant-a', $resolved->tenantId);
        self::assertSame('service-account-offer', $resolved->subject);
    }

    public function testItRejectsTenantHeaderWithoutDelegationScope(): void
    {
        $principal = new AuthenticatedPrincipal('service-account-offer', null, ['pricing:quote']);
        $this->expectException(TenantDelegationException::class);

        (new TenantPrincipalResolver())->resolve($principal, 'tenant-a');
    }

    public function testItRejectsAttemptToOverrideVerifiedTenant(): void
    {
        $principal = new AuthenticatedPrincipal('user-1', 'tenant-a', ['offers:read']);
        $this->expectException(TenantDelegationException::class);

        (new TenantPrincipalResolver())->resolve($principal, 'tenant-b');
    }

    public function testItRejectsMalformedDelegatedTenantIdentifier(): void
    {
        $principal = new AuthenticatedPrincipal('service-account-offer', null, [
            TenantPrincipalResolver::DELEGATION_SCOPE,
        ]);
        $this->expectException(TenantDelegationException::class);

        (new TenantPrincipalResolver())->resolve($principal, 'tenant/a');
    }
}
