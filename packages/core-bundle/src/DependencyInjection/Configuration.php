<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('autoflow_core');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('correlation_id')->defaultTrue()->end()
                ->scalarNode('correlation_header')->defaultValue('X-Correlation-ID')->end()
                ->booleanNode('health_endpoints')->defaultTrue()->end()
            ->end();

        return $treeBuilder;
    }
}
