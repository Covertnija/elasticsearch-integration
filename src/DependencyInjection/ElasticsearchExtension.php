<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\DependencyInjection;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

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
     * @param array<mixed> $configs The configuration arrays
     * @param ContainerBuilder $container The container builder
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        /** @var array{enabled: bool, hosts: array<string>, api_key: string|null, client_options: array<string, mixed>} $config */
        if (! $config['enabled']) {
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
     * @param array{enabled: bool, hosts: array<string>, api_key: string|null, client_options: array<string, mixed>} $config The processed configuration
     */
    private function registerClientFactory(ContainerBuilder $container, array $config): void
    {
        $factoryDefinition = new Definition(ElasticsearchRoundRobinClientFactory::class);
        $factoryDefinition->addArgument(
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        );
        $factoryDefinition->addTag('monolog.logger', ['channel' => 'elasticsearch']);

        $container->setDefinition(
            'elasticsearch_integration.client_factory',
            $factoryDefinition,
        );

        $container->setAlias(
            ElasticsearchRoundRobinClientFactory::class,
            'elasticsearch_integration.client_factory',
        )->setPublic(false);

        $container->setAlias(
            ElasticsearchClientFactoryInterface::class,
            'elasticsearch_integration.client_factory',
        )->setPublic(false);
    }

    /**
     * Register the Elasticsearch client service.
     *
     * @param ContainerBuilder $container The container builder
     * @param array{enabled: bool, hosts: array<string>, api_key: string|null, client_options: array<string, mixed>} $config The processed configuration
     */
    private function registerElasticsearchClient(ContainerBuilder $container, array $config): void
    {
        $clientDefinition = new Definition(Client::class);
        $clientDefinition->setFactory([
            new Reference('elasticsearch_integration.client_factory'),
            'createClient',
        ]);

        $clientDefinition->setArguments([
            $config['hosts'],
            $config['api_key'],
            $config['client_options'],
        ]);

        $container->setDefinition(
            'elasticsearch_integration.client',
            $clientDefinition,
        );

        $container->setAlias(
            'elasticsearch.client',
            'elasticsearch_integration.client',
        )->setPublic(true);

        $container->setAlias(
            Client::class,
            'elasticsearch_integration.client',
        )->setPublic(false);
    }

    /**
     * Register configuration parameters.
     *
     * @param ContainerBuilder $container The container builder
     * @param array{enabled: bool, hosts: array<string>, api_key: string|null, client_options: array<string, mixed>} $config The processed configuration
     */
    private function registerParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('elasticsearch_integration.enabled', $config['enabled']);
        $container->setParameter('elasticsearch_integration.hosts', $config['hosts']);
        $container->setParameter('elasticsearch_integration.api_key', $config['api_key']);
        $container->setParameter('elasticsearch_integration.client_options', $config['client_options']);
    }

    /**
     * Get the extension alias.
     *
     * @return string The extension alias
     */
    public function getAlias(): string
    {
        return 'elasticsearch_integration';
    }
}
