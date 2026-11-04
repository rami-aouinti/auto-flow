<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('core');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('correlation_id')->defaultTrue()->end()
                ->scalarNode('correlation_header')->defaultValue('X-Correlation-ID')->end()
                ->arrayNode('authentication')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('issuer')->defaultValue('')->end()
                        ->scalarNode('audience')->defaultValue('')->end()
                        ->scalarNode('jwks_uri')->defaultValue('')->end()
                        ->integerNode('jwks_cache_ttl')->min(1)->defaultValue(300)->end()
                        ->arrayNode('protected_path_prefixes')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/api/'])
                        ->end()
                        ->arrayNode('excluded_paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/api/doc', '/api/doc.json'])
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
