<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class JwtAuthenticationSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $protectedPathPrefixes
     * @param list<string> $excludedPaths
     */
    public function __construct(
        private JwtTokenVerifier $tokenVerifier,
        private bool $enabled,
        private array $protectedPathPrefixes,
        private array $excludedPaths,
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
        $path = $request->getPathInfo();

        foreach ($this->excludedPaths as $excludedPath) {
            if ($path === $excludedPath) {
                return;
            }
        }

        $requiresAuthentication = false;
        foreach ($this->protectedPathPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $requiresAuthentication = true;
                break;
            }
        }

        if (!$requiresAuthentication) {
            return;
        }

        $authorization = $request->headers->get('Authorization', '');
        if (!is_string($authorization) || preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            $event->setResponse($this->unauthorized());

            return;
        }

        try {
            $principal = $this->tokenVerifier->verify(trim($matches[1]));
            $request->attributes->set(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal);
        } catch (JwksUnavailableException) {
            $event->setResponse(new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Authentication service unavailable',
                'status' => 503,
                'detail' => 'The signing keys cannot currently be retrieved.',
            ], JsonResponse::HTTP_SERVICE_UNAVAILABLE));
        } catch (InvalidTokenException) {
            $event->setResponse($this->unauthorized());
        }
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
