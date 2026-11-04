<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Security;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequiresScope
{
    public function __construct(public string $scope)
    {
        if (trim($scope) === '') {
            throw new \InvalidArgumentException('A required scope cannot be empty.');
        }
    }
}
