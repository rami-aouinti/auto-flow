<?php

declare(strict_types=1);

namespace App\Presentation\Http\EventSubscriber;

use App\Application\Exception\CatalogAccessDeniedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CatalogAccessDeniedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof CatalogAccessDeniedException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => 'Forbidden',
                'status' => 403,
                'detail' => 'The authenticated actor does not have the required catalogue scope.',
                'errorCode' => 'INSUFFICIENT_CATALOGUE_SCOPE',
                'instance' => $event->getRequest()->getPathInfo(),
            ],
            403,
            [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ],
        ));
    }
}
