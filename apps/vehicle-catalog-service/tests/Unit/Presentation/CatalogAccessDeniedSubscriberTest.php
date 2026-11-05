<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation;

use App\Application\Exception\CatalogAccessDeniedException;
use App\Presentation\Http\EventSubscriber\CatalogAccessDeniedSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CatalogAccessDeniedSubscriberTest extends TestCase
{
    public function testInsufficientApplicationScopeBecomesForbiddenProblemResponse(): void
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(
                Request $request,
                int $type = self::MAIN_REQUEST,
                bool $catch = true,
            ): Response {
                return new Response();
            }
        };

        $event = new ExceptionEvent(
            $kernel,
            Request::create('/api/v1/vehicles'),
            HttpKernelInterface::MAIN_REQUEST,
            new CatalogAccessDeniedException('Missing catalogue scope.'),
        );

        (new CatalogAccessDeniedSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'INSUFFICIENT_CATALOGUE_SCOPE',
            (string) $response->getContent(),
        );
    }
}
