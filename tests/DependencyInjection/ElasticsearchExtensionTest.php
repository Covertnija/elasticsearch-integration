<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\DependencyInjection;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\DependencyInjection\ElasticsearchExtension;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

final class ElasticsearchExtensionTest extends TestCase
{
    private ElasticsearchExtension $extension;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new ElasticsearchExtension();
        $this->container = new ContainerBuilder();
    }

    public function testGetAlias(): void
    {
        self::assertSame('elasticsearch_integration', $this->extension->getAlias());
    }

    public function testServicesRegisteredWhenEnabled(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        self::assertFalse($this->container->hasDefinition('elasticsearch_integration.round_robin_http_client'));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client_factory'));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client'));
        self::assertTrue($this->container->hasAlias('elasticsearch.client'));
        self::assertTrue($this->container->hasAlias(Client::class));
        self::assertTrue($this->container->hasAlias(ElasticsearchRoundRobinClientFactory::class));
        self::assertTrue($this->container->hasAlias(ElasticsearchClientFactoryInterface::class));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.kibana_formatter'));
        self::assertTrue($this->container->hasAlias(KibanaCompatibleFormatter::class));
    }

    public function testServicesAlwaysRegisteredWhenDisabled(): void
    {
        $this->extension->load([[
            'enabled' => false,
        ]], $this->container);

        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client_factory'));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client'));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.kibana_formatter'));
    }

    public function testParametersRegistered(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://es1:9200', 'http://es2:9200'],
            'api_key' => 'test-key',
            'index' => 'test-index',
            'level' => 'error',
            'client_options' => ['retries' => 3],
        ]], $this->container);

        self::assertTrue($this->container->getParameter('elasticsearch_integration.enabled'));
        self::assertSame(['http://es1:9200', 'http://es2:9200'], $this->container->getParameter('elasticsearch_integration.hosts'));
        self::assertFalse($this->container->hasParameter('elasticsearch_integration.api_key'));
        self::assertSame('test-index', $this->container->getParameter('elasticsearch_integration.index'));
        self::assertSame('error', $this->container->getParameter('elasticsearch_integration.level'));
        self::assertSame(['retries' => 3], $this->container->getParameter('elasticsearch_integration.client_options'));
        self::assertSame('index', $this->container->getParameter('elasticsearch_integration.op_type'));
        self::assertSame(5.0, $this->container->getParameter('elasticsearch_integration.timeout'));
        self::assertSame(300, $this->container->getParameter('elasticsearch_integration.retry_after_seconds'));
    }

    public function testFailSafeParametersRegistered(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://es1:9200'],
            'op_type' => 'create',
            'timeout' => 2.5,
            'retry_after_seconds' => 60,
        ]], $this->container);

        self::assertSame('create', $this->container->getParameter('elasticsearch_integration.op_type'));
        self::assertSame(2.5, $this->container->getParameter('elasticsearch_integration.timeout'));
        self::assertSame(60, $this->container->getParameter('elasticsearch_integration.retry_after_seconds'));
    }

    public function testClientFactoryHasLoggerArgument(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.client_factory');
        $arguments = $definition->getArguments();

        self::assertCount(1, $arguments);
    }

    public function testClientFactoryHasMonologTag(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.client_factory');
        $tags = $definition->getTags();

        self::assertArrayHasKey('monolog.logger', $tags);
        self::assertSame('elasticsearch', $tags['monolog.logger'][0]['channel']);
    }

    public function testClientDefinitionUsesFactory(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'api_key' => 'test-key',
            'client_options' => ['retries' => 5],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.client');
        $factory = $definition->getFactory();

        self::assertIsArray($factory);
        self::assertSame('createClient', $factory[1]);

        $arguments = $definition->getArguments();
        self::assertSame('%elasticsearch_integration.hosts%', $arguments[0]);
        self::assertSame('test-key', $arguments[1]);
        self::assertSame(['retries' => 5, 'timeout' => 5.0], $arguments[2]);
    }

    public function testClientOptionsTimeoutWinsOverConfiguredTimeout(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'timeout' => 2.5,
            'client_options' => ['timeout' => 9.0],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.client');
        $arguments = $definition->getArguments();

        self::assertSame(['timeout' => 9.0], $arguments[2]);
    }

    public function testElasticsearchClientAliasIsPrivate(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $alias = $this->container->getAlias('elasticsearch.client');
        self::assertFalse($alias->isPublic());
    }

    public function testClientClassAliasIsPrivate(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $alias = $this->container->getAlias(Client::class);
        self::assertFalse($alias->isPublic());
    }

    public function testDefaultConfigurationApplied(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->getParameter('elasticsearch_integration.enabled'));
        self::assertSame(['http://localhost:9200'], $this->container->getParameter('elasticsearch_integration.hosts'));
        self::assertSame('app-logs', $this->container->getParameter('elasticsearch_integration.index'));
        self::assertSame('debug', $this->container->getParameter('elasticsearch_integration.level'));
        self::assertSame([], $this->container->getParameter('elasticsearch_integration.client_options'));
    }

    public function testKibanaFormatterRegisteredWithIndex(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'index' => 'custom-logs',
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.kibana_formatter');
        $arguments = $definition->getArguments();

        self::assertCount(1, $arguments);
        self::assertSame('%elasticsearch_integration.index%', $arguments[0]);
        self::assertSame('custom-logs', $this->container->getParameter('elasticsearch_integration.index'));
    }

    public function testElasticsearchClientIsNotLazy(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.client');

        self::assertFalse($definition->isLazy());
    }

    public function testMonologHandlerRegistered(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'index' => 'test-index',
        ]], $this->container);

        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.monolog_handler'));

        $definition = $this->container->getDefinition('elasticsearch_integration.monolog_handler');
        self::assertSame(LazyElasticsearchHandler::class, $definition->getClass());

        $arguments = $definition->getArguments();
        self::assertSame('%elasticsearch_integration.enabled%', $arguments[2]);

        $tags = $definition->getTags();
        self::assertArrayHasKey('monolog.logger', $tags);
        self::assertSame('elasticsearch', $tags['monolog.logger'][0]['channel']);
    }

    public function testMonologHandlerClientArgumentIsServiceClosure(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.monolog_handler');
        $arguments = $definition->getArguments();

        self::assertInstanceOf(ServiceClosureArgument::class, $arguments[0]);

        $reference = $arguments[0]->getValues()[0];
        self::assertInstanceOf(Reference::class, $reference);
        self::assertSame('elasticsearch_integration.client', (string) $reference);
    }

    public function testMonologHandlerHasOpTypeLevelAndRetryArguments(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'level' => 'warning',
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.monolog_handler');
        $arguments = $definition->getArguments();

        self::assertSame(
            [
                'index' => '%elasticsearch_integration.index%',
                'op_type' => '%elasticsearch_integration.op_type%',
            ],
            $arguments[1],
        );
        self::assertSame('warning', $arguments['$level']);
        self::assertSame('%elasticsearch_integration.retry_after_seconds%', $arguments['$retryAfterSeconds']);
    }

    public function testInvalidLiteralLevelRejectedAtLoad(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid value "verbose" for "elasticsearch_integration.level"');

        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'level' => 'verbose',
        ]], $this->container);
    }

    public function testUppercaseLevelAccepted(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'level' => 'INFO',
        ]], $this->container);

        self::assertSame('INFO', $this->container->getParameter('elasticsearch_integration.level'));
    }

    public function testWarningLevelAccepted(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'level' => 'warning',
        ]], $this->container);

        self::assertSame('warning', $this->container->getParameter('elasticsearch_integration.level'));
    }

    public function testEnvPlaceholderLevelAccepted(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'level' => '%env(LOG_LEVEL)%',
        ]], $this->container);

        self::assertSame('%env(LOG_LEVEL)%', $this->container->getParameter('elasticsearch_integration.level'));
    }

    public function testMonologHandlerCacheArgumentDefaultsToCacheApp(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.monolog_handler');
        $arguments = $definition->getArguments();

        self::assertArrayHasKey('$cache', $arguments);
        self::assertInstanceOf(Reference::class, $arguments['$cache']);
        self::assertSame('cache.app', (string) $arguments['$cache']);
        self::assertSame(
            ContainerInterface::NULL_ON_INVALID_REFERENCE,
            $arguments['$cache']->getInvalidBehavior(),
        );
    }

    public function testMonologHandlerCacheArgumentAbsentWhenCachePoolNull(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'cache_pool' => null,
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.monolog_handler');
        $arguments = $definition->getArguments();

        self::assertArrayNotHasKey('$cache', $arguments);
    }

    public function testMonologHandlerCacheArgumentUsesConfiguredPool(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'cache_pool' => 'my.cache_pool',
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.monolog_handler');
        $arguments = $definition->getArguments();

        self::assertSame('my.cache_pool', (string) $arguments['$cache']);
    }

    public function testMonologHandlerAliasIsPrivate(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $alias = $this->container->getAlias(LazyElasticsearchHandler::class);
        self::assertFalse($alias->isPublic());
    }
}
