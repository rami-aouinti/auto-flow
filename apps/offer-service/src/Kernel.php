<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Contracts\Service\ResetInterface;

final class Kernel extends BaseKernel implements ResetInterface
{
    use MicroKernelTrait;

    public function reset(): void
    {
        if ($this->container instanceof ResetInterface) {
            $this->container->reset();
        }
    }
}
