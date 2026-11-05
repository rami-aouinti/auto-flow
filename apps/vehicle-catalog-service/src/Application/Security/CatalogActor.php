<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Exception\CatalogAccessDeniedException;

final readonly class CatalogActor
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $subject,
        public array $scopes,
    ) {
    }

    public function requireScope(string $scope): void
    {
        if (!in_array($scope, $this->scopes, true)) {
            throw new CatalogAccessDeniedException(sprintf('The actor requires the %s scope.', $scope));
        }
    }
}
