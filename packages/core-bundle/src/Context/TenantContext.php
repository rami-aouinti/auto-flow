<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Context;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Symfony\Component\HttpFoundation\RequestStack;

final class TenantContext
{
    private ?string $activeTenantId = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getTenantId(): ?string
    {
        if ($this->activeTenantId !== null) {
            return $this->activeTenantId;
        }

        $principal = $this->requestStack->getCurrentRequest()?->attributes->get(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
        );

        return $principal instanceof AuthenticatedPrincipal ? $principal->tenantId : null;
    }

    public function requireTenantId(): string
    {
        $tenantId = $this->getTenantId();
        if ($tenantId === null) {
            throw new MissingTenantContextException('A tenant context is required for this operation.');
        }

        return $tenantId;
    }

    /**
     * @template TResult
     * @param callable(): TResult $work
     * @return TResult
     */
    public function run(string $tenantId, callable $work): mixed
    {
        $previous = $this->activeTenantId;
        $this->activeTenantId = $tenantId;

        try {
            return $work();
        } finally {
            $this->activeTenantId = $previous;
        }
    }
}
