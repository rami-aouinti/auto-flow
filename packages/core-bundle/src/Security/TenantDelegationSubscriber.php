<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class TenantDelegationSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $pathPrefixes
     */
    public function __construct(
        private array $pathPrefixes,
        private TenantPrincipalResolver $principalResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->requiresTenant($event->getRequest()->getPathInfo())) {
            return;
        }

        $request = $event->getRequest();
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal) {
            $event->setResponse($this->forbidden());

            return;
        }

        try {
            $resolved = $this->principalResolver->resolve(
                $principal,
                $request->headers->get('X-Tenant-ID'),
            );
            $request->attributes->set(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $resolved);
        } catch (TenantDelegationException) {
            $event->setResponse($this->forbidden());
        }
    }

    private function requiresTenant(string $path): bool
    {
        foreach ($this->pathPrefixes as $prefix) {
            $normalizedPrefix = rtrim($prefix, '/');
            if ($path === $normalizedPrefix || str_starts_with($path, $normalizedPrefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Tenant context required',
            'status' => 403,
            'detail' => 'A verified tenant claim or an explicitly authorized tenant delegation is required.',
        ], JsonResponse::HTTP_FORBIDDEN, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
