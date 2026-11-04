<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Health;

interface HealthCheckInterface
{
    public function name(): string;

    public function check(): HealthCheckResult;
}
