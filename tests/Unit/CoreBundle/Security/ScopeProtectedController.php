<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Security;

use AutoFlow\CoreBundle\Security\RequiresScope;

final class ScopeProtectedController
{
    #[RequiresScope('pricing:quote')]
    public function execute(): void
    {
    }
}
