<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Messenger;

use AutoFlow\CoreBundle\Context\CorrelationContext;
use AutoFlow\CoreBundle\Infrastructure\Messenger\CorrelationIdMiddleware;
use AutoFlow\CoreBundle\Messaging\CorrelationIdStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class CorrelationIdMiddlewareTest extends TestCase
{
    public function testItPassesTheStackToTheNextMiddlewareAndRestoresContext(): void
    {
        $context = new CorrelationContext();
        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $stack = $this->createMock(StackInterface::class);

        $nextMiddleware
            ->expects(self::once())
            ->method('handle')
            ->with(
                self::callback(
                    static fn (Envelope $envelope): bool => $envelope->last(CorrelationIdStamp::class) instanceof CorrelationIdStamp,
                ),
                self::identicalTo($stack),
            )
            ->willReturnCallback(
                static fn (Envelope $envelope, StackInterface $stack): Envelope => $envelope,
            );

        $stack->method('next')->willReturn($nextMiddleware);

        $middleware = new CorrelationIdMiddleware($context);
        $result = $middleware->handle(Envelope::wrap(new \stdClass()), $stack);

        self::assertInstanceOf(CorrelationIdStamp::class, $result->last(CorrelationIdStamp::class));
        self::assertNull($context->get());
    }
}
