<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Application\Clock;

use DateTimeImmutable;

/**
 * Application-facing clock port. Use it instead of calling the system clock
 * directly when time affects business decisions or workflow scheduling.
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
