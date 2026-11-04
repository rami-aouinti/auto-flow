<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Messaging;

use InvalidArgumentException;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TenantStamp implements StampInterface
{
    public function __construct(public string $tenantId)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $tenantId) !== 1) {
            throw new InvalidArgumentException('The tenant identifier has an invalid format.');
        }
    }
}
