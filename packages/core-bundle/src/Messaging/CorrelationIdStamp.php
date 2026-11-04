<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Messaging;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class CorrelationIdStamp implements StampInterface
{
    public function __construct(public string $correlationId)
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $correlationId) !== 1) {
            throw new \InvalidArgumentException('The correlation ID has an invalid format.');
        }
    }
}
