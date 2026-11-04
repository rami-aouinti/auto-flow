<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle;

use AutoFlow\CoreBundle\Health\HealthCheckInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CoreBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container
            ->registerForAutoconfiguration(HealthCheckInterface::class)
            ->addTag('autoflow_core.health_check');
    }
}
