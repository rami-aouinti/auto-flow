<?php

declare(strict_types=1);

namespace AutoFlow\\CoreBundle\\Infrastructure\\Clock;

use AutoFlow\\CoreBundle\\Application\\Clock\\ClockInterface;
use DateTimeImmutable;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
