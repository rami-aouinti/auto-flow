<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface DomainEvent
{
    /** @return array<string, mixed> */
    public function toEnvelope(): array;
}
