<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\DependencyInjection;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientInterface as ElasticsearchClientInterface;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
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
        /** @var array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>} $processedConfig */
        $processedConfig = $this->processConfiguration($configuration, $configs);

        $this->registerParameters($container, $processedConfig);
        $this->registerRoundRobinHttpClient($container);
        $this->registerClientFactory($container);
        $this->registerElasticsearchClient($container);
        $this->registerKibanaFormatter($container);
    }

    private function registerRoundRobinHttpClient(ContainerBuilder $container): void
    {
        $definition = new Definition(RoundRobinHttpClient::class);
        $definition->setArguments([
            '%elasticsearch_integration.hosts%',
            null,
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $definition->addTag('monolog.logger', ['channel' => 'elasticsearch']);
        $definition->setLazy(true);
        $definition->addTag('proxy', ['interface' => ClientInterface::class]);

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

    private function registerElasticsearchClient(ContainerBuilder $container): void
    {
        $clientDefinition = new Definition(Client::class);
        $clientDefinition->setFactory([
            new Reference('elasticsearch_integration.client_factory'),
            'createClient',
        ]);

        $clientDefinition->setArguments([
            '%elasticsearch_integration.hosts%',
            '%elasticsearch_integration.api_key%',
            ['httpClient' => new Reference('elasticsearch_integration.round_robin_http_client')],
        ]);
        $clientDefinition->setLazy(true);
        $clientDefinition->addTag('proxy', ['interface' => ElasticsearchClientInterface::class]);

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

    private function registerKibanaFormatter(ContainerBuilder $container): void
    {
        $formatterDefinition = new Definition(KibanaCompatibleFormatter::class);
        $formatterDefinition->addArgument('%elasticsearch_integration.index%');

        $container->setDefinition(
            'elasticsearch_integration.kibana_formatter',
            $formatterDefinition,
        );

        $container->setAlias(
            KibanaCompatibleFormatter::class,
            'elasticsearch_integration.kibana_formatter',
        )->setPublic(false);
    }

    /**
     * @param array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>} $config
     */
    private function registerParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('elasticsearch_integration.enabled', $config['enabled']);
        $container->setParameter('elasticsearch_integration.hosts', $config['hosts']);
        $container->setParameter('elasticsearch_integration.api_key', $config['api_key']);
        $container->setParameter('elasticsearch_integration.client_options', $config['client_options']);
        $container->setParameter('elasticsearch_integration.index', $config['index']);
    }

    public function getAlias(): string
    {
        return 'elasticsearch_integration';
    }
}
