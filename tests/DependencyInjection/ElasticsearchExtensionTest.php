<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\DependencyInjection;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\DependencyInjection\ElasticsearchExtension;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for ElasticsearchExtension.
 */
final class ElasticsearchExtensionTest extends TestCase
{
    private ElasticsearchExtension $extension;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new ElasticsearchExtension();
        $this->container = new ContainerBuilder();
    }

    /**
     * Test extension alias.
     */
    public function testGetAlias(): void
    {
        self::assertSame('elasticsearch_integration', $this->extension->getAlias());
    }

    /**
     * Test that services are registered when enabled.
     */
    public function testServicesRegisteredWhenEnabled(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client_factory'));
        self::assertTrue($this->container->hasDefinition('elasticsearch_integration.client'));
        self::assertTrue($this->container->hasAlias('elasticsearch.client'));
        self::assertTrue($this->container->hasAlias(Client::class));
        self::assertTrue($this->container->hasAlias(ElasticsearchRoundRobinClientFactory::class));
        self::assertTrue($this->container->hasAlias(ElasticsearchClientFactoryInterface::class));
    }

    /**
     * Test that services are not registered when disabled.
     */
    public function testServicesNotRegisteredWhenDisabled(): void
    {
        $this->extension->load([[
            'enabled' => false,
        ]], $this->container);

        self::assertFalse($this->container->hasDefinition('elasticsearch_integration.client_factory'));
        self::assertFalse($this->container->hasDefinition('elasticsearch_integration.client'));
    }

    /**
     * Test parameters are registered correctly.
     */
    public function testParametersRegistered(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://es1:9200', 'http://es2:9200'],
            'api_key' => 'test-key',
            'client_options' => ['retries' => 3],
        ]], $this->container);

        self::assertTrue($this->container->getParameter('elasticsearch_integration.enabled'));
        self::assertSame(['http://es1:9200', 'http://es2:9200'], $this->container->getParameter('elasticsearch_integration.hosts'));
        self::assertSame('test-key', $this->container->getParameter('elasticsearch_integration.api_key'));
        self::assertSame(['retries' => 3], $this->container->getParameter('elasticsearch_integration.client_options'));
    }

    /**
     * Test client factory has logger argument.
     */
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

    /**
     * Test client factory has monolog tag.
     */
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

    /**
     * Test client definition uses factory.
     */
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
        self::assertSame(['retries' => 5], $arguments[2]);
    }

    /**
     * Test elasticsearch.client alias is public.
     */
    public function testElasticsearchClientAliasIsPublic(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $alias = $this->container->getAlias('elasticsearch.client');
        self::assertTrue($alias->isPublic());
    }

    /**
     * Test Client::class alias is private.
     */
    public function testClientClassAliasIsPrivate(): void
    {
        $this->extension->load([[
            'enabled' => true,
            'hosts' => ['http://localhost:9200'],
        ]], $this->container);

        $alias = $this->container->getAlias(Client::class);
        self::assertFalse($alias->isPublic());
    }

    /**
     * Test default configuration values are applied.
     */
    public function testDefaultConfigurationApplied(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->getParameter('elasticsearch_integration.enabled'));
        self::assertSame(['http://localhost:9200'], $this->container->getParameter('elasticsearch_integration.hosts'));
        self::assertNull($this->container->getParameter('elasticsearch_integration.api_key'));
        self::assertSame([], $this->container->getParameter('elasticsearch_integration.client_options'));
    }
}
