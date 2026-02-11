<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Integration;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\DependencyInjection\ElasticsearchExtension;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
        self::assertTrue($container->has('elasticsearch_integration.round_robin_http_client'));
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

        self::assertFalse($container->has('elasticsearch_integration.client_factory'));
        self::assertFalse($container->has('elasticsearch_integration.client'));
        self::assertFalse($container->has('elasticsearch_integration.kibana_formatter'));
        self::assertFalse($container->has('elasticsearch_integration.round_robin_http_client'));
    }

    public function testClientFactoryServiceResolvesToCorrectClass(): void
    {
        $container = $this->createCompiledContainer([]);

        $definition = $container->getDefinition('elasticsearch_integration.client_factory');
        self::assertSame(ElasticsearchRoundRobinClientFactory::class, $definition->getClass());
    }

    public function testRoundRobinHttpClientServiceResolvesToCorrectClass(): void
    {
        $container = $this->createCompiledContainer([]);

        $definition = $container->getDefinition('elasticsearch_integration.round_robin_http_client');
        self::assertSame(RoundRobinHttpClient::class, $definition->getClass());
    }

    public function testKibanaFormatterReceivesConfiguredIndex(): void
    {
        $container = $this->createCompiledContainer([
            'index' => 'my-custom-index',
        ]);

        $definition = $container->getDefinition('elasticsearch_integration.kibana_formatter');
        self::assertSame(KibanaCompatibleFormatter::class, $definition->getClass());
        self::assertSame('my-custom-index', $definition->getArgument(0));
    }

    public function testClientDefinitionUsesFactoryMethod(): void
    {
        $container = $this->createCompiledContainer([]);

        $definition = $container->getDefinition('elasticsearch_integration.client');
        $factory = $definition->getFactory();

        self::assertIsArray($factory);
        self::assertSame('createClient', $factory[1]);
    }

    public function testApiKeyNotExposedAsParameter(): void
    {
        $container = $this->createCompiledContainer([
            'api_key' => 'secret-key',
        ]);

        self::assertFalse($container->hasParameter('elasticsearch_integration.api_key'));
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
            RoundRobinHttpClient::class,
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

    public function testRoundRobinHttpClientInjectedIntoClientOptions(): void
    {
        $container = $this->createCompiledContainer([]);

        $definition = $container->getDefinition('elasticsearch_integration.client');
        $arguments = $definition->getArguments();

        self::assertArrayHasKey('httpClient', $arguments[2]);
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
}
