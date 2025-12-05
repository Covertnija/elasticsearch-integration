<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration definition for Elasticsearch integration bundle.
 *
 * This class defines the configuration schema for the Elasticsearch
 * integration, including host configuration, authentication, and
 * client options.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * Generate the configuration tree builder.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('elasticsearch_integration');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Enable or disable Elasticsearch integration')
                ->end()
                ->arrayNode('hosts')
                    ->requiresAtLeastOneElement()
                    ->defaultValue(['http://localhost:9200'])
                    ->info('Array of Elasticsearch host URLs')
                    ->prototype('scalar')
                        ->cannotBeEmpty()
                    ->end()
                ->end()
                ->scalarNode('api_key')
                    ->defaultNull()
                    ->info('Elasticsearch API key for authentication')
                ->end()
                ->scalarNode('index')
                    ->defaultValue('app-logs')
                    ->info('Default Elasticsearch index name')
                ->end()
                ->arrayNode('client_options')
                    ->info('Additional Elasticsearch client options')
                    ->normalizeKeys(false)
                    ->variablePrototype()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
