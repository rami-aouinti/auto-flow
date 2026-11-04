<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Messenger;

use AutoFlow\CoreBundle\Context\CorrelationContext;
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
        $previous = $this->context->get();
        $stamp = $envelope->last(CorrelationIdStamp::class);

        if ($stamp instanceof CorrelationIdStamp) {
            $this->context->set($stamp->correlationId);
        } elseif ($previous !== null) {
            $envelope = $envelope->with(new CorrelationIdStamp($previous));
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->context->set($previous);
        }
    }
}
