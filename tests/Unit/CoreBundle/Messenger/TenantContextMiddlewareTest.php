<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Messenger;

use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Context\TenantContextMismatchException;
use AutoFlow\CoreBundle\Infrastructure\Messenger\TenantContextMiddleware;
use AutoFlow\CoreBundle\Messaging\TenantStamp;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TenantContextMiddlewareTest extends TestCase
{
    public function testItAddsTenantStampAndScopesTheHandler(): void
    {
        $context = new TenantContext(new RequestStack());
        $next = $this->createMock(MiddlewareInterface::class);
        $stack = $this->createMock(StackInterface::class);
        $next->expects(self::once())->method('handle')->willReturnCallback(
            static function (Envelope $envelope, StackInterface $stack) use ($context): Envelope {
                self::assertSame('tenant-a', $context->requireTenantId());
                self::assertInstanceOf(TenantStamp::class, $envelope->last(TenantStamp::class));

                return $envelope;
            },
        );
        $stack->method('next')->willReturn($next);
        $middleware = new TenantContextMiddleware($context);

        $result = $context->run(
            'tenant-a',
            static fn (): Envelope => $middleware->handle(new Envelope(new stdClass()), $stack),
        );

        self::assertInstanceOf(TenantStamp::class, $result->last(TenantStamp::class));
        self::assertNull($context->getTenantId());
    }

    public function testItRejectsAStampThatChangesTheActiveTenant(): void
    {
        $context = new TenantContext(new RequestStack());
        $middleware = new TenantContextMiddleware($context);
        $envelope = (new Envelope(new stdClass()))->with(new TenantStamp('tenant-b'));
        $stack = $this->createMock(StackInterface::class);

        $this->expectException(TenantContextMismatchException::class);
        $context->run('tenant-a', static fn (): Envelope => $middleware->handle($envelope, $stack));
    }
}
