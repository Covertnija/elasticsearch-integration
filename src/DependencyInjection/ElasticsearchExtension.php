<?php

declare(strict_types=1);

namespace EV\ElasticsearchIntegration\DependencyInjection;

use EV\ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Elasticsearch\Client;

/**
 * Dependency injection extension for Elasticsearch integration bundle.
 *
 * This extension loads and processes the configuration for the
 * Elasticsearch integration, registering necessary services
 * and parameters in the DI container.
 */
final class ElasticsearchExtension extends Extension
{
    /**
     * Load the extension configuration.
     *
     * @param array<string, mixed> $configs The configuration arrays
     * @param ContainerBuilder $container The container builder
     *
     * @throws \InvalidArgumentException If configuration is invalid
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        if (!$config['enabled']) {
            return;
        }

        $this->registerClientFactory($container, $config);
        $this->registerElasticsearchClient($container, $config);
        $this->registerParameters($container, $config);
    }

    /**
     * Register the Elasticsearch client factory service.
     *
     * @param ContainerBuilder $container The container builder
     * @param array<string, mixed> $config The processed configuration
     */
    private function registerClientFactory(ContainerBuilder $container, array $config): void
    {
        $factoryDefinition = new Definition(ElasticsearchRoundRobinClientFactory::class);
        $factoryDefinition->addArgument(
            new Reference('logger', ContainerBuilder::NULL_ON_INVALID_REFERENCE)
        );
        $factoryDefinition->addTag('monolog.logger', ['channel' => 'elasticsearch']);
        
        $container->setDefinition(
            'ev_elasticsearch_integration.client_factory',
            $factoryDefinition
        );

        $container->setAlias(
            ElasticsearchRoundRobinClientFactory::class,
            'ev_elasticsearch_integration.client_factory'
        )->setPublic(false);
    }

    /**
     * Register the Elasticsearch client service.
     *
     * @param ContainerBuilder $container The container builder
     * @param array<string, mixed> $config The processed configuration
     */
    private function registerElasticsearchClient(ContainerBuilder $container, array $config): void
    {
        $clientDefinition = new Definition(Client::class);
        $clientDefinition->setFactory([
            new Reference('ev_elasticsearch_integration.client_factory'),
            'createClient',
        ]);

        $clientDefinition->setArguments([
            $config['hosts'],
            $config['api_key'],
            $config['client_options'] ?? [],
        ]);

        $container->setDefinition(
            'ev_elasticsearch_integration.client',
            $clientDefinition
        );

        $container->setAlias(
            'elasticsearch.client',
            'ev_elasticsearch_integration.client'
        )->setPublic(true);

        $container->setAlias(
            Client::class,
            'ev_elasticsearch_integration.client'
        )->setPublic(false);
    }

    /**
     * Register configuration parameters.
     *
     * @param ContainerBuilder $container The container builder
     * @param array<string, mixed> $config The processed configuration
     */
    private function registerParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('ev_elasticsearch_integration.enabled', $config['enabled']);
        $container->setParameter('ev_elasticsearch_integration.hosts', $config['hosts']);
        $container->setParameter('ev_elasticsearch_integration.api_key', $config['api_key']);
        $container->setParameter('ev_elasticsearch_integration.client_options', $config['client_options'] ?? []);
        $container->setParameter('ev_elasticsearch_integration.logging.enabled', $config['logging']['enabled']);
        $container->setParameter('ev_elasticsearch_integration.logging.level', $config['logging']['level']);
    }

    /**
     * Get the extension alias.
     *
     * @return string The extension alias
     */
    public function getAlias(): string
    {
        return 'ev_elasticsearch_integration';
    }
}
