<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Context\CorrelationContext;
use AutoFlow\CoreBundle\Infrastructure\Messenger\CorrelationIdMiddleware;
use AutoFlow\CoreBundle\Messaging\CorrelationIdStamp;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class CorrelationIdMiddlewareTest extends TestCase
{
    public function testItAddsCorrelationStampAndRestoresContext(): void
    {
        $context = new CorrelationContext();
        $context->set('outer-request');
        $middleware = new CorrelationIdMiddleware($context);
        $message = new stdClass();

        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->with(
                self::callback(static function (Envelope $envelope): bool {
                    $stamp = $envelope->last(CorrelationIdStamp::class);

                    return $stamp instanceof CorrelationIdStamp
                        && $stamp->correlationId === 'outer-request';
                }),
                self::isInstanceOf(StackInterface::class),
            )
            ->willReturnCallback(static fn (Envelope $envelope, StackInterface $stack): Envelope => $envelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())->method('next')->willReturn($next);

        $result = $middleware->handle(new Envelope($message), $stack);

        self::assertInstanceOf(CorrelationIdStamp::class, $result->last(CorrelationIdStamp::class));
        self::assertSame('outer-request', $context->get());
    }

    public function testItReusesExistingStampDuringMessageHandling(): void
    {
        $context = new CorrelationContext();
        $context->set('outer-request');
        $middleware = new CorrelationIdMiddleware($context);
        $envelope = (new Envelope(new stdClass()))->with(new CorrelationIdStamp('message-123'));

        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->with(
                self::callback(static function (Envelope $received) use ($context): bool {
                    self::assertSame('message-123', $context->get());

                    return $received->last(CorrelationIdStamp::class) instanceof CorrelationIdStamp;
                }),
                self::isInstanceOf(StackInterface::class),
            )
            ->willReturnArgument(0);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::once())->method('next')->willReturn($next);

        $middleware->handle($envelope, $stack);

        self::assertSame('outer-request', $context->get());
    }
}
