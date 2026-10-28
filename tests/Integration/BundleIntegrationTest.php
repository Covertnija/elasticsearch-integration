<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Integration;

use DateTimeImmutable;
use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\DependencyInjection\ElasticsearchExtension;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;

/**
 * Integration tests for the Elasticsearch bundle container compilation.
 *
 * These tests verify that the full DI container compiles correctly
 * and services are properly wired together.
 */
final class BundleIntegrationTest extends TestCase
{
    public function testContainerCompilesWithDefaultConfig(): void
    {
        $container = $this->createCompiledContainer([]);

        self::assertTrue($container->has('elasticsearch_integration.client_factory'));
        self::assertTrue($container->has('elasticsearch_integration.client'));
        self::assertTrue($container->has('elasticsearch_integration.kibana_formatter'));
        self::assertFalse($container->has('elasticsearch_integration.round_robin_http_client'));
    }

    public function testContainerCompilesWithCustomConfig(): void
    {
        $container = $this->createCompiledContainer([
            'enabled' => true,
            'hosts' => ['http://es1:9200', 'http://es2:9200'],
            'api_key' => 'integration-test-key',
            'index' => 'integration-logs',
            'client_options' => ['retries' => 3],
        ]);

        self::assertSame(
            ['http://es1:9200', 'http://es2:9200'],
            $container->getParameter('elasticsearch_integration.hosts'),
        );
        self::assertSame('integration-logs', $container->getParameter('elasticsearch_integration.index'));
    }

    public function testContainerCompilesWhenDisabled(): void
    {
        $container = $this->createCompiledContainer(['enabled' => false]);

        self::assertFalse($container->getParameter('elasticsearch_integration.enabled'));
        self::assertTrue($container->has('elasticsearch_integration.client_factory'));
        self::assertTrue($container->has('elasticsearch_integration.client'));
    }

    public function testClientFactoryServiceResolvesToCorrectClass(): void
    {
        $container = $this->createCompiledContainer([]);

        $definition = $container->getDefinition('elasticsearch_integration.client_factory');
        self::assertSame(ElasticsearchRoundRobinClientFactory::class, $definition->getClass());
    }

    public function testKibanaFormatterReceivesConfiguredIndex(): void
    {
        $container = $this->createCompiledContainer([
            'index' => 'my-custom-index',
        ]);

        $definition = $container->getDefinition('elasticsearch_integration.kibana_formatter');
        self::assertSame(KibanaCompatibleFormatter::class, $definition->getClass());
        self::assertSame('%elasticsearch_integration.index%', $definition->getArgument(0));
        self::assertSame('my-custom-index', $container->getParameter('elasticsearch_integration.index'));
    }

    public function testClientDefinitionUsesFactoryMethod(): void
    {
        $container = $this->createCompiledContainer([]);

        $definition = $container->getDefinition('elasticsearch_integration.client');
        $factory = $definition->getFactory();

        self::assertIsArray($factory);
        self::assertSame('createClient', $factory[1]);
    }

    public function testAllAliasesArePrivate(): void
    {
        $container = $this->createCompiledContainer([]);

        $aliases = [
            'elasticsearch.client',
            Client::class,
            ElasticsearchRoundRobinClientFactory::class,
            ElasticsearchClientFactoryInterface::class,
            KibanaCompatibleFormatter::class,
        ];

        foreach ($aliases as $alias) {
            self::assertFalse(
                $container->getAlias($alias)->isPublic(),
                sprintf('Alias "%s" should be private', $alias),
            );
        }
    }

    public function testStringHostIsNormalizedToArray(): void
    {
        $container = $this->createCompiledContainer([
            'hosts' => 'http://single-host:9200',
        ]);

        self::assertSame(
            ['http://single-host:9200'],
            $container->getParameter('elasticsearch_integration.hosts'),
        );
    }

    public function testDisabledHandlerHandlesRecordsWithoutResolvingClient(): void
    {
        $container = new ContainerBuilder();
        $extension = new ElasticsearchExtension();
        $extension->load([[
            'enabled' => false,
            'hosts' => [],
        ]], $container);

        $container->setAlias('test.elasticsearch_handler', 'elasticsearch_integration.monolog_handler')
            ->setPublic(true);
        $container->compile();

        $handler = $container->get('test.elasticsearch_handler');
        self::assertInstanceOf(LazyElasticsearchHandler::class, $handler);

        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'Test message',
        );

        $handler->handle($record);
        $handler->handleBatch([$record]);

        self::assertFalse($container->initialized('elasticsearch_integration.client'));
    }

    public function testStringDisabledHandlerDoesNotResolveClient(): void
    {
        $container = new ContainerBuilder();
        $extension = new ElasticsearchExtension();
        $extension->load([[
            'enabled' => 'false',
            'hosts' => [],
        ]], $container);

        $container->setAlias('test.elasticsearch_handler', 'elasticsearch_integration.monolog_handler')
            ->setPublic(true);
        $container->compile();

        $handler = $container->get('test.elasticsearch_handler');
        self::assertInstanceOf(LazyElasticsearchHandler::class, $handler);

        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'Test message',
        );

        $handler->handle($record);
        $handler->handleBatch([$record]);

        self::assertFalse($container->initialized('elasticsearch_integration.client'));
    }

    public function testHandlerBuildsWithoutCacheAppService(): void
    {
        $container = new ContainerBuilder();
        $extension = new ElasticsearchExtension();
        $extension->load([[
            'enabled' => false,
            'hosts' => [],
        ]], $container);

        $container->setAlias('test.elasticsearch_handler', 'elasticsearch_integration.monolog_handler')
            ->setPublic(true);
        $container->compile();

        $handler = $container->get('test.elasticsearch_handler');
        self::assertInstanceOf(LazyElasticsearchHandler::class, $handler);
    }

    public function testContainerCompilesWithLevelEnvPlaceholder(): void
    {
        $container = $this->createCompiledContainerWithEnvVars(
            [
                'ELASTICSEARCH_ENABLED' => 'true',
                'ELASTICSEARCH_HOSTS' => 'http://localhost:9200',
                'ELASTICSEARCH_API_KEY' => '',
                'ELASTICSEARCH_INDEX' => 'app_logs',
                'LOG_LEVEL' => 'warning',
            ],
            [
                'enabled' => '%env(bool:ELASTICSEARCH_ENABLED)%',
                'hosts' => '%env(csv:ELASTICSEARCH_HOSTS)%',
                'api_key' => '%env(default::ELASTICSEARCH_API_KEY)%',
                'index' => '%env(ELASTICSEARCH_INDEX)%',
                'level' => '%env(LOG_LEVEL)%',
            ],
        );

        self::assertSame('warning', $container->getParameter('elasticsearch_integration.level'));
    }

    public function testContainerCompilesWithEnvVarConfig(): void
    {
        $container = $this->createCompiledContainerWithEnvVars(
            [
                'ELASTICSEARCH_ENABLED' => 'true',
                'ELASTICSEARCH_HOSTS' => 'http://es1:9200,http://es2:9200',
                'ELASTICSEARCH_API_KEY' => 'test-key',
                'ELASTICSEARCH_INDEX' => 'app_logs',
            ],
            [
                'enabled' => '%env(bool:ELASTICSEARCH_ENABLED)%',
                'hosts' => '%env(csv:ELASTICSEARCH_HOSTS)%',
                'api_key' => '%env(default::ELASTICSEARCH_API_KEY)%',
                'index' => '%env(ELASTICSEARCH_INDEX)%',
            ],
        );

        self::assertTrue($container->hasParameter('elasticsearch_integration.enabled'));
    }

    public function testContainerCompilesWithDisabledEnvVar(): void
    {
        $container = $this->createCompiledContainerWithEnvVars(
            [
                'ELASTICSEARCH_ENABLED' => 'false',
                'ELASTICSEARCH_HOSTS' => '',
                'ELASTICSEARCH_API_KEY' => '',
                'ELASTICSEARCH_INDEX' => 'app_logs',
            ],
            [
                'enabled' => '%env(bool:ELASTICSEARCH_ENABLED)%',
                'hosts' => '%env(csv:ELASTICSEARCH_HOSTS)%',
                'api_key' => '%env(default::ELASTICSEARCH_API_KEY)%',
                'index' => '%env(ELASTICSEARCH_INDEX)%',
            ],
        );

        self::assertTrue($container->hasParameter('elasticsearch_integration.enabled'));
    }

    public function testContainerCompilesWithSingleEnvVarHost(): void
    {
        $container = $this->createCompiledContainerWithEnvVars(
            [
                'ELASTICSEARCH_ENABLED' => 'true',
                'ELASTICSEARCH_HOSTS' => 'http://localhost:9200',
                'ELASTICSEARCH_API_KEY' => '',
                'ELASTICSEARCH_INDEX' => 'app_logs',
            ],
            [
                'enabled' => '%env(bool:ELASTICSEARCH_ENABLED)%',
                'hosts' => '%env(csv:ELASTICSEARCH_HOSTS)%',
                'api_key' => '%env(default::ELASTICSEARCH_API_KEY)%',
                'index' => '%env(ELASTICSEARCH_INDEX)%',
            ],
        );

        self::assertTrue($container->hasParameter('elasticsearch_integration.enabled'));
    }

    public function testKernelPathCompilesWithLevelEnvPlaceholder(): void
    {
        $_ENV['LOG_LEVEL'] = 'info';
        $_SERVER['LOG_LEVEL'] = 'info';

        try {
            $container = new ContainerBuilder(new EnvPlaceholderParameterBag());
            $container->registerExtension($extension = new ElasticsearchExtension());
            $container->loadFromExtension('elasticsearch_integration', [
                'level' => '%env(LOG_LEVEL)%',
            ]);
            $container->compile(true);

            self::assertSame('info', $container->getParameter('elasticsearch_integration.level'));
        } finally {
            unset($_ENV['LOG_LEVEL'], $_SERVER['LOG_LEVEL']);
        }
    }

    public function testKernelPathRejectsLiteralInvalidLevelDuringCompile(): void
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag());
        $container->registerExtension(new ElasticsearchExtension());
        $container->loadFromExtension('elasticsearch_integration', [
            'level' => 'verbose',
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid value "verbose" for "elasticsearch_integration.level"');

        $container->compile();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createCompiledContainer(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = new ElasticsearchExtension();
        $extension->load([$config], $container);

        return $container;
    }

    /**
     * Simulates how a real Symfony project loads the bundle with environment variables.
     *
     * @param array<string, string> $envVars
     * @param array<string, mixed> $config
     */
    private function createCompiledContainerWithEnvVars(array $envVars, array $config): ContainerBuilder
    {
        foreach ($envVars as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $bag = new EnvPlaceholderParameterBag();
            $container = new ContainerBuilder($bag);

            $extension = new ElasticsearchExtension();
            $extension->load([$config], $container);
            $container->compile(true);

            return $container;
        } finally {
            foreach (array_keys($envVars) as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }
}
