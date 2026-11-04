<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Security;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\ScopeAuthorizationSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ScopeAuthorizationSubscriberTest extends TestCase
{
    public function testItReplacesControllerWhenRequiredScopeIsMissing(): void
    {
        $event = $this->createEvent(new AuthenticatedPrincipal('user-1', 'tenant-a', ['offers:read']));
        (new ScopeAuthorizationSubscriber())->onKernelController($event);

        $response = ($event->getController())();

        self::assertSame(403, $response->getStatusCode());
    }

    public function testItKeepsControllerWhenRequiredScopeIsGranted(): void
    {
        $controller = new ScopeProtectedController();
        $event = $this->createEvent(
            new AuthenticatedPrincipal('user-1', 'tenant-a', ['pricing:quote']),
            $controller,
        );
        (new ScopeAuthorizationSubscriber())->onKernelController($event);

        self::assertSame([$controller, 'execute'], $event->getController());
    }

    private function createEvent(
        AuthenticatedPrincipal $principal,
        ?ScopeProtectedController $controller = null,
    ): ControllerEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->attributes->set(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal);

        return new ControllerEvent(
            $kernel,
            [$controller ?? new ScopeProtectedController(), 'execute'],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
