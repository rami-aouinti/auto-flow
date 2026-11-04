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

    /**
     * Run work in a correlation scope and always restore the previous value.
     *
     * @template TResult
     * @param callable(): TResult $work
     * @return TResult
     */
    public function run(string $correlationId, callable $work): mixed
    {
        $previous = $this->correlationId;
        $this->correlationId = $correlationId;

        try {
            return $work();
        } finally {
            $this->correlationId = $previous;
        }
    }
}
