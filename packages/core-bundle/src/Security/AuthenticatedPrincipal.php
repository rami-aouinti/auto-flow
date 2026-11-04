<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

final readonly class AuthenticatedPrincipal
{
    public const REQUEST_ATTRIBUTE = '_autoflow_authenticated_principal';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $subject,
        public ?string $tenantId,
        public array $scopes,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
