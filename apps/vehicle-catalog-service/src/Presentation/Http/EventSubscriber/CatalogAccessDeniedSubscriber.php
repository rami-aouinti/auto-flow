<?php

declare(strict_types=1);

namespace App\Presentation\Http\EventSubscriber;

use App\Application\Exception\CatalogAccessDeniedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class CatalogAccessDeniedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof UnauthorizedHttpException) {
            $status = 401;
            $title = 'Unauthorized';
            $detail = 'A valid bearer token is required to access the catalogue.';
            $headers = $exception->getHeaders();
        } elseif (
            $exception instanceof CatalogAccessDeniedException
            || $exception instanceof AccessDeniedHttpException
        ) {
            $status = 403;
            $title = 'Forbidden';
            $detail = 'The authenticated actor does not have the required catalogue scope.';
            $headers = [];
        } else {
            return;
        }

        $headers['Content-Type'] = 'application/problem+json';
        $headers['Cache-Control'] = 'no-store';

        $event->setResponse(new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'errorCode' => $status === 401 ? 'UNAUTHENTICATED' : 'INSUFFICIENT_CATALOGUE_SCOPE',
                'instance' => $event->getRequest()->getPathInfo(),
            ],
            $status,
            $headers,
        ));
    }
}
