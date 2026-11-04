<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\Messenger;

use AutoFlow\CoreBundle\Context\CorrelationContext;
use AutoFlow\CoreBundle\Messaging\CorrelationIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(private CorrelationContext $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(CorrelationIdStamp::class);
        $correlationId = $stamp instanceof CorrelationIdStamp
            ? $stamp->correlationId
            : ($this->context->get() ?? bin2hex(random_bytes(16)));

        if (!$stamp instanceof CorrelationIdStamp) {
            $envelope = $envelope->with(new CorrelationIdStamp($correlationId));
        }

        return $this->context->run(
            $correlationId,
            static fn (): Envelope => $stack->next()->handle($envelope, $stack),
        );
    }
}
