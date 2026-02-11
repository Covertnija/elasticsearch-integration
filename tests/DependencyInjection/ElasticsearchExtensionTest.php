<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\DependencyInjection;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\DependencyInjection\ElasticsearchExtension;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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

        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.round_robin_http_client'));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client_factory'));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client'));
        self::assertTrue($this->container->hasAlias('elasticsearch.client'));
        self::assertTrue($this->container->hasAlias(Client::class));
        self::assertTrue($this->container->hasAlias(RoundRobinHttpClient::class));
        self::assertTrue($this->container->hasAlias(ElasticsearchRoundRobinClientFactory::class));
        self::assertTrue($this->container->hasAlias(ElasticsearchClientFactoryInterface::class));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.kibana_formatter'));
        self::assertTrue($this->container->hasAlias(KibanaCompatibleFormatter::class));
    }

    public function testServicesNotRegisteredWhenDisabled(): void
    {
        $this->extension->load([[
            'enabled' => false,
        ]], $this->container);

        self::assertFalse($this->container->hasDefinition('elasticsearch_integration.round_robin_http_client'));
        self::assertFalse($this->container->hasDefinition('elasticsearch_integration.client_factory'));
        self::assertFalse($this->container->hasDefinition('elasticsearch_integration.client'));
        self::assertFalse($this->container->hasDefinition('elasticsearch_integration.kibana_formatter'));
    }

    public function testParametersRegistered(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://es1:9200', 'http://es2:9200'],
            'api_key' => 'test-key',
            'index' => 'test-index',
            'client_options' => ['retries' => 3],
        ]], $this->container);

        self::assertTrue($this->container->getParameter('elasticsearch_integration.enabled'));
        self::assertSame(['http://es1:9200', 'http://es2:9200'], $this->container->getParameter('elasticsearch_integration.hosts'));
        self::assertFalse($this->container->hasParameter('elasticsearch_integration.api_key'));
        self::assertSame('test-index', $this->container->getParameter('elasticsearch_integration.index'));
        self::assertSame(['retries' => 3], $this->container->getParameter('elasticsearch_integration.client_options'));
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
        self::assertSame(['http://localhost:9200'], $arguments[0]);
        self::assertSame('test-key', $arguments[1]);
        self::assertSame(5, $arguments[2]['retries']);
        self::assertArrayHasKey('httpClient', $arguments[2]);
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
        self::assertSame('custom-logs', $arguments[0]);
    }

    public function testRoundRobinHttpClientRegistered(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://es1:9200', 'http://es2:9200'],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.round_robin_http_client');
        $arguments = $definition->getArguments();

        self::assertSame(['http://es1:9200', 'http://es2:9200'], $arguments[0]);
    }

    public function testRoundRobinHttpClientAliasIsPrivate(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $alias = $this->container->getAlias(RoundRobinHttpClient::class);
        self::assertFalse($alias->isPublic());
    }

    public function testClientOptionsIncludeRoundRobinHttpClient(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
            'client_options' => ['retries' => 5],
        ]], $this->container);

        $definition = $this->container->getDefinition('elasticsearch_integration.client');
        $arguments = $definition->getArguments();

        self::assertArrayHasKey('httpClient', $arguments[2]);
        self::assertSame(5, $arguments[2]['retries']);
    }
}
