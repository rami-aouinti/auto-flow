<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Health;

final readonly class HealthCheckResult
{
    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        public bool $healthy,
        public array $details = [],
    ) {
    }

    public static function healthy(array $details = []): self
    {
        return new self(true, $details);
    }

    public static function unhealthy(array $details = []): self
    {
        return new self(false, $details);
    }
}
