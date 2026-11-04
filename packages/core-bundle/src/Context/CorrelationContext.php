<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Context;

final class CorrelationContext
{
    private ?string $correlationId = null;

    public function get(): ?string
    {
        return $this->correlationId;
    }

    public function set(?string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function clear(): void
    {
        $this->correlationId = null;
    }
}
