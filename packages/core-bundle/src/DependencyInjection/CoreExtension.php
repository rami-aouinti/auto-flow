<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\DependencyInjection;

use InvalidArgumentException;
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
        $authentication = $config['authentication'];

        $container->setParameter('autoflow_core.correlation_header', $config['correlation_header']);
        $container->setParameter('autoflow_core.jwt.enabled', $authentication['enabled']);
        $container->setParameter('autoflow_core.jwt.issuer', $authentication['issuer']);
        $container->setParameter('autoflow_core.jwt.audience', $authentication['audience']);
        $container->setParameter('autoflow_core.jwt.jwks_uri', $authentication['jwks_uri']);
        $container->setParameter('autoflow_core.jwt.jwks_cache_ttl', $authentication['jwks_cache_ttl']);
        $container->setParameter(
            'autoflow_core.jwt.protected_path_prefixes',
            $authentication['protected_path_prefixes'],
        );
        $container->setParameter(
            'autoflow_core.jwt.excluded_paths',
            $authentication['excluded_paths'],
        );

        if ($authentication['enabled']) {
            foreach (['issuer', 'audience', 'jwks_uri'] as $required) {
                if (!is_string($authentication[$required]) || trim($authentication[$required]) === '') {
                    throw new InvalidArgumentException(sprintf('Core JWT authentication requires a non-empty "%s".', $required));
                }
            }
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if (!$config['correlation_id']) {
            $container->removeDefinition(\AutoFlow\CoreBundle\Http\Correlation\CorrelationIdSubscriber::class);
            $container->removeDefinition(\AutoFlow\CoreBundle\Http\Client\CorrelationIdHttpClient::class);
        }

        if (!$authentication['enabled']) {
            $container->removeDefinition(\AutoFlow\CoreBundle\Security\JwtAuthenticationSubscriber::class);
        }
    }

    public function getAlias(): string
    {
        return 'core';
    }
}
