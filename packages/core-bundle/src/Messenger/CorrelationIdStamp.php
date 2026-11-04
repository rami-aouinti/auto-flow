<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class CorrelationIdStamp implements StampInterface
{
    public function __construct(public string $correlationId)
    {
    }
}
