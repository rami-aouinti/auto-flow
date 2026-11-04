<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Security;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\TenantDelegationSubscriber;
use AutoFlow\CoreBundle\Security\TenantPrincipalResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TenantDelegationSubscriberTest extends TestCase
{
    public function testItResolvesDelegatedTenantForTrustedServiceToken(): void
    {
        $request = new Request(server: ['REQUEST_URI' => '/api/pricing/quotes']);
        $request->headers->set('X-Tenant-ID', 'tenant-acme');
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('service-account-offer', null, [
                'pricing:quote', TenantPrincipalResolver::DELEGATION_SCOPE,
            ]),
        );
        $event = $this->createEvent($request);

        $subscriber = new TenantDelegationSubscriber(['/api/pricing/'], new TenantPrincipalResolver());
        $subscriber->onKernelRequest($event);

        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        self::assertSame('tenant-acme', $principal->tenantId);
        self::assertNull($event->getResponse());
    }

    public function testItRejectsDelegationWithoutScope(): void
    {
        $request = new Request(server: ['REQUEST_URI' => '/api/pricing/quotes']);
        $request->headers->set('X-Tenant-ID', 'tenant-acme');
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('service-account-other', null, ['pricing:quote']),
        );
        $event = $this->createEvent($request);

        $subscriber = new TenantDelegationSubscriber(['/api/pricing/'], new TenantPrincipalResolver());
        $subscriber->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    private function createEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
