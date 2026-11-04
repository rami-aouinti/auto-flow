<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

final class TenantPrincipalResolver
{
    public const string DELEGATION_SCOPE = 'tenant:delegate';

    public function resolve(
        AuthenticatedPrincipal $principal,
        ?string $delegatedTenantId,
    ): AuthenticatedPrincipal {
        if ($principal->tenantId !== null) {
            if ($delegatedTenantId !== null && $delegatedTenantId !== $principal->tenantId) {
                throw new TenantDelegationException('A delegated tenant cannot override the authenticated tenant.');
            }

            return $principal;
        }

        if (
            !$principal->hasScope(self::DELEGATION_SCOPE)
            || $delegatedTenantId === null
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $delegatedTenantId) !== 1
        ) {
            throw new TenantDelegationException('The service token is not allowed to delegate a valid tenant.');
        }

        return new AuthenticatedPrincipal(
            $principal->subject,
            $delegatedTenantId,
            $principal->scopes,
        );
    }
}
