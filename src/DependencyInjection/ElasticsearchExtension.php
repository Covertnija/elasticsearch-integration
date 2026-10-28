<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\DependencyInjection;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
use ElasticsearchIntegration\Util\HostNormalizer;
use InvalidArgumentException;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

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
        /** @var array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>, level: string, op_type: string, timeout: float, retry_after_seconds: int, cache_pool: string|null} $processedConfig */
        $processedConfig = $this->processConfiguration($configuration, $configs);

        $this->validateLevel($container, $processedConfig['level']);

        $this->registerParameters($container, $processedConfig);
        $this->registerClientFactory($container);
        $this->registerElasticsearchClient($container, $processedConfig);
        $this->registerKibanaFormatter($container);
        $this->registerMonologHandler($container, $processedConfig);
    }

    /**
     * Validates a literal level the same way the handler does at runtime;
     * env placeholders are resolved later and can not be checked here.
     *
     * @throws InvalidConfigurationException
     */
    private function validateLevel(ContainerBuilder $container, mixed $level): void
    {
        if (! is_string($level)
            || str_contains($level, '%env(')
            || $container->resolveEnvPlaceholders($level) !== $level
        ) {
            return;
        }

        try {
            // @phpstan-ignore argument.type
            Logger::toMonologLevel($level);
        } catch (Throwable) {
            throw new InvalidConfigurationException(sprintf('Invalid value "%s" for "elasticsearch_integration.level"; expected one of debug, info, notice, warning, error, critical, alert, emergency.', $level));
        }
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

    /**
     * @param array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>, level: string, op_type: string, timeout: float, retry_after_seconds: int, cache_pool: string|null} $config
     */
    private function registerElasticsearchClient(ContainerBuilder $container, array $config): void
    {
        $clientDefinition = new Definition(Client::class);
        $clientDefinition->setFactory([
            new Reference('elasticsearch_integration.client_factory'),
            'createClient',
        ]);

        $clientDefinition->setArguments([
            '%elasticsearch_integration.hosts%',
            $config['api_key'],
            $config['client_options'] + ['timeout' => $config['timeout']],
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
     * @param array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>, level: string, op_type: string, timeout: float, retry_after_seconds: int, cache_pool: string|null} $config
     */
    private function registerMonologHandler(ContainerBuilder $container, array $config): void
    {
        $handlerDefinition = new Definition(LazyElasticsearchHandler::class);
        $handlerDefinition->setArguments([
            new ServiceClosureArgument(new Reference('elasticsearch_integration.client')),
            [
                'index' => '%elasticsearch_integration.index%',
                'op_type' => '%elasticsearch_integration.op_type%',
            ],
            '%elasticsearch_integration.enabled%',
            new Reference('elasticsearch_integration.kibana_formatter'),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $handlerDefinition->setArgument('$level', $config['level']);
        $handlerDefinition->setArgument('$retryAfterSeconds', '%elasticsearch_integration.retry_after_seconds%');

        if ($config['cache_pool'] !== null) {
            $handlerDefinition->setArgument('$cache', new Reference(
                $config['cache_pool'],
                ContainerInterface::NULL_ON_INVALID_REFERENCE,
            ));
        }
        $handlerDefinition->addTag('monolog.logger', ['channel' => 'elasticsearch']);

        $container->setDefinition(
            'elasticsearch_integration.monolog_handler',
            $handlerDefinition,
        );

        $container->setAlias(
            LazyElasticsearchHandler::class,
            'elasticsearch_integration.monolog_handler',
        )->setPublic(false);
    }

    /**
     * @param array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>, level: string, op_type: string, timeout: float, retry_after_seconds: int, cache_pool: string|null} $config
     */
    private function registerParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('elasticsearch_integration.enabled', $config['enabled']);
        $container->setParameter('elasticsearch_integration.hosts', HostNormalizer::normalize($config['hosts']));
        $container->setParameter('elasticsearch_integration.client_options', $config['client_options']);
        $container->setParameter('elasticsearch_integration.index', $config['index']);
        $container->setParameter('elasticsearch_integration.level', $config['level']);
        $container->setParameter('elasticsearch_integration.op_type', $config['op_type']);
        $container->setParameter('elasticsearch_integration.timeout', $config['timeout']);
        $container->setParameter('elasticsearch_integration.retry_after_seconds', $config['retry_after_seconds']);
    }

    public function getAlias(): string
    {
        return 'elasticsearch_integration';
    }
}
