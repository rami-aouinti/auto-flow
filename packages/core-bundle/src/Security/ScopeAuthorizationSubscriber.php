<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ScopeAuthorizationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onKernelController', 10]];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        if (
            !is_array($controller)
            || (!is_object($controller[0]) && !is_string($controller[0]))
            || !is_string($controller[1])
        ) {
            return;
        }

        $method = new ReflectionMethod($controller[0], (string) $controller[1]);
        $attributes = $method->getAttributes(RequiresScope::class);
        if ($attributes === []) {
            return;
        }

        $principal = $event->getRequest()->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        $required = $attributes[0]->newInstance()->scope;
        if ($principal instanceof AuthenticatedPrincipal && $principal->hasScope($required)) {
            return;
        }

        $event->setController(static fn (): JsonResponse => new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'The access token does not grant the required permission.',
        ], JsonResponse::HTTP_FORBIDDEN, [
            'Cache-Control' => 'no-store',
        ]));
    }
}
