<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CoreExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('autoflow_core.correlation_header', $config['correlation_header']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if (!$config['correlation_id']) {
            $container->removeDefinition(\AutoFlow\CoreBundle\Http\Correlation\CorrelationIdSubscriber::class);
            $container->removeDefinition(\AutoFlow\CoreBundle\Http\Client\CorrelationIdHttpClient::class);
        }
    }

    public function getAlias(): string
    {
        return 'autoflow_core';
    }
}
