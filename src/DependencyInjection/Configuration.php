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
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('elasticsearch_integration');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('enabled')
                    ->defaultTrue()
                    ->info('Enable or disable Elasticsearch integration')
                ->end()
                ->arrayNode('hosts')
                    ->beforeNormalization()
                        ->ifString()
                        ->then(static fn (string $v): array => [$v])
                    ->end()
                    ->defaultValue(['http://localhost:9200'])
                    ->info('Array of Elasticsearch host URLs')
                    ->variablePrototype()->end()
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
                ->enumNode('op_type')
                    ->values(['index', 'create'])
                    ->defaultValue('index')
                    ->info('Bulk action type; Elasticsearch data streams require "create"')
                ->end()
                ->floatNode('timeout')
                    ->min(0.1)
                    ->defaultValue(5.0)
                    ->info('HTTP timeout in seconds for Elasticsearch requests (idle and total)')
                ->end()
                ->integerNode('retry_after_seconds')
                    ->min(0)
                    ->defaultValue(300)
                    ->info('Seconds to stop sending logs after a failure')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
