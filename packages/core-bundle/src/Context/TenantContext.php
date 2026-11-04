<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Context;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class TenantContext
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function getTenantId(): ?string
    {
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
}
