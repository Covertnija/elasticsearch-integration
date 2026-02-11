<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\DependencyInjection;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
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
     * @param array<mixed> $configs
     *
     * @throws InvalidArgumentException
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{enabled: bool, hosts: array<string>, api_key: string|null, index: string, client_options: array<string, mixed>} $processedConfig */
        $processedConfig = $this->processConfiguration($configuration, $configs);
        $config = ElasticsearchConfig::fromArray($processedConfig);

        if (! $config->enabled) {
            return;
        }

        $this->registerRoundRobinHttpClient($container, $config);
        $this->registerClientFactory($container);
        $this->registerElasticsearchClient($container, $config);
        $this->registerKibanaFormatter($container, $config);
        $this->registerParameters($container, $config);
    }

    private function registerRoundRobinHttpClient(ContainerBuilder $container, ElasticsearchConfig $config): void
    {
        $definition = new Definition(RoundRobinHttpClient::class);
        $definition->setArguments([
            $config->hosts,
            null,
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $definition->addTag('monolog.logger', ['channel' => 'elasticsearch']);

        $container->setDefinition(
            'elasticsearch_integration.round_robin_http_client',
            $definition,
        );

        $container->setAlias(
            RoundRobinHttpClient::class,
            'elasticsearch_integration.round_robin_http_client',
        )->setPublic(false);
    }

    private function registerClientFactory(ContainerBuilder $container): void
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

    private function registerElasticsearchClient(ContainerBuilder $container, ElasticsearchConfig $config): void
    {
        $clientOptions = array_merge($config->clientOptions, [
            'httpClient' => new Reference('elasticsearch_integration.round_robin_http_client'),
        ]);

        $clientDefinition = new Definition(Client::class);
        $clientDefinition->setFactory([
            new Reference('elasticsearch_integration.client_factory'),
            'createClient',
        ]);

        $clientDefinition->setArguments([
            $config->hosts,
            $config->apiKey,
            $clientOptions,
        ]);

        $container->setDefinition(
            'elasticsearch_integration.client',
            $clientDefinition,
        );

        $container->setAlias(
            'elasticsearch.client',
            'elasticsearch_integration.client',
        )->setPublic(false);

        $container->setAlias(
            Client::class,
            'elasticsearch_integration.client',
        )->setPublic(false);
    }

    private function registerKibanaFormatter(ContainerBuilder $container, ElasticsearchConfig $config): void
    {
        $formatterDefinition = new Definition(KibanaCompatibleFormatter::class);
        $formatterDefinition->addArgument($config->index);

        $container->setDefinition(
            'elasticsearch_integration.kibana_formatter',
            $formatterDefinition,
        );

        $container->setAlias(
            KibanaCompatibleFormatter::class,
            'elasticsearch_integration.kibana_formatter',
        )->setPublic(false);
    }

    private function registerParameters(ContainerBuilder $container, ElasticsearchConfig $config): void
    {
        $container->setParameter('elasticsearch_integration.enabled', $config->enabled);
        $container->setParameter('elasticsearch_integration.hosts', $config->hosts);
        $container->setParameter('elasticsearch_integration.client_options', $config->clientOptions);
        $container->setParameter('elasticsearch_integration.index', $config->index);
    }

    public function getAlias(): string
    {
        return 'elasticsearch_integration';
    }
}
