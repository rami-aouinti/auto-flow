<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Infrastructure\Messenger;

use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Context\TenantContextMismatchException;
use AutoFlow\CoreBundle\Messaging\TenantStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(private TenantContext $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(TenantStamp::class);
        $currentTenantId = $this->context->getTenantId();

        if ($stamp instanceof TenantStamp && $currentTenantId !== null && $stamp->tenantId !== $currentTenantId) {
            throw new TenantContextMismatchException('A message cannot switch the active tenant context.');
        }

        $tenantId = $stamp instanceof TenantStamp ? $stamp->tenantId : $currentTenantId;
        if ($tenantId === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (!$stamp instanceof TenantStamp) {
            $envelope = $envelope->with(new TenantStamp($tenantId));
        }

        return $this->context->run(
            $tenantId,
            static fn (): Envelope => $stack->next()->handle($envelope, $stack),
        );
    }
}
