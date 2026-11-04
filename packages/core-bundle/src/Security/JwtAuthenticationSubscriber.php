<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class JwtAuthenticationSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $protectedPrefixes
     * @param list<string> $excludedPaths
     * @param list<string> $tenantPathPrefixes
     */
    public function __construct(
        private JwtTokenVerifier $tokenVerifier,
        private bool $enabled,
        private array $protectedPrefixes,
        private array $excludedPaths,
        private array $tenantPathPrefixes,
        private TenantPrincipalResolver $tenantPrincipalResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isProtectedPath($request->getPathInfo())) {
            return;
        }

        $token = $this->extractBearerToken($request);
        if ($token === null) {
            $event->setResponse($this->unauthorized());

            return;
        }

        $this->authenticate($event, $request, $token);
    }

    private function isProtectedPath(string $path): bool
    {
        if (in_array($path, $this->excludedPaths, true)) {
            return false;
        }

        foreach ($this->protectedPrefixes as $prefix) {
            $normalizedPrefix = rtrim($prefix, '/');

            if ($normalizedPrefix === '') {
                if (str_starts_with($path, '/')) {
                    return true;
                }

                continue;
            }

            if ($path === $normalizedPrefix || str_starts_with($path, $normalizedPrefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization', '');
        if (!is_string($authorization) || preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function authenticate(RequestEvent $event, Request $request, string $token): void
    {
        try {
            $principal = $this->tokenVerifier->verify($token);
            if ($this->requiresTenant($request->getPathInfo()) && $principal->tenantId === null) {
                $event->setResponse(new JsonResponse([
                    'type' => 'about:blank',
                    'title' => 'Tenant context required',
                    'status' => 403,
                    'detail' => 'This endpoint requires a tenant-scoped access token.',
                ], JsonResponse::HTTP_FORBIDDEN, [
                    'Cache-Control' => 'no-store',
                ]));

                return;
            }

            $request->attributes->set(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal);
        } catch (JwksUnavailableException) {
            $event->setResponse(new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Authentication service unavailable',
                'status' => 503,
                'detail' => 'The signing keys cannot currently be retrieved.',
            ], JsonResponse::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'no-store',
            ]));
        } catch (InvalidTokenException) {
            $event->setResponse($this->unauthorized());
        }
    }

    private function requiresTenant(string $path): bool
    {
        foreach ($this->tenantPathPrefixes as $prefix) {
            $normalizedPrefix = rtrim($prefix, '/');
            if ($path === $normalizedPrefix || str_starts_with($path, $normalizedPrefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'A valid bearer access token is required.',
        ], JsonResponse::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Bearer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
