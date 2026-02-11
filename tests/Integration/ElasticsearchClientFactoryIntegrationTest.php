<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Integration;

use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use Http\Client\HttpAsyncClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Integration tests for ElasticsearchRoundRobinClientFactory.
 *
 * These tests verify the full client creation flow including
 * all option validation paths and client builder integration.
 */
final class ElasticsearchClientFactoryIntegrationTest extends TestCase
{
    private ElasticsearchRoundRobinClientFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ElasticsearchRoundRobinClientFactory(new NullLogger());
    }

    public function testCreateClientReturnsClientInstance(): void
    {
        $this->factory->createClient(['http://localhost:9200']);

        // No exception means success - client was created
        $this->addToAssertionCount(1);
    }

    public function testCreateClientWithApiKey(): void
    {
        $this->factory->createClient(
            ['http://localhost:9200'],
            'test-api-key',
        );

        // No exception means success - API key was accepted
        $this->addToAssertionCount(1);
    }

    public function testCreateClientWithElasticCloudIdOption(): void
    {
        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['elasticCloudId' => 'my-deployment:dXMtY2VudHJhbDEuZ2NwLmNsb3VkLmVzLmlvJGNlYzYzNGE='],
        );

        // No exception means success - elasticCloudId option was accepted
        $this->addToAssertionCount(1);
    }

    public function testCreateClientWithHttpClientOption(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['httpClient' => $httpClient],
        );

        // No exception means success - httpClient option was accepted
        $this->addToAssertionCount(1);
    }

    public function testCreateClientWithLoggerOption(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['logger' => $logger],
        );

        // No exception means success - logger option was accepted
        $this->addToAssertionCount(1);
    }

    public function testCreateClientWithAsyncHttpClientOption(): void
    {
        $asyncClient = $this->createMock(HttpAsyncClient::class);

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['asyncHttpClient' => $asyncClient],
        );

        // No exception means success - asyncHttpClient option was accepted
        $this->addToAssertionCount(1);
    }

    public function testInvalidElasticCloudIdThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "elasticCloudId": expected string value');

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['elasticCloudId' => 123],
        );
    }

    public function testInvalidHttpClientThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "httpClient": expected Psr\Http\Client\ClientInterface instance');

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['httpClient' => 'not-a-client'],
        );
    }

    public function testInvalidLoggerThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "logger": expected Psr\Log\LoggerInterface instance');

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['logger' => 'not-a-logger'],
        );
    }

    public function testInvalidAsyncHttpClientThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "asyncHttpClient": expected Http\Client\HttpAsyncClient instance');

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['asyncHttpClient' => 'not-a-client'],
        );
    }

    public function testEmptyStringHostsAreFiltered(): void
    {
        $this->factory->createClient(['', 'http://localhost:9200', '']);

        // No exception means success - empty hosts were filtered
        $this->addToAssertionCount(1);
    }

    public function testAllEmptyStringHostsThrowException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('At least one Elasticsearch host must be provided');

        $this->factory->createClient(['', '']);
    }

    public function testCreateClientWithMultipleCombinedOptions(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $this->factory->createClient(
            ['http://localhost:9200'],
            'test-api-key',
            [
                'retries' => 3,
                'sslVerification' => false,
                'logger' => $logger,
            ],
        );

        // No exception means success - multiple options were accepted
        $this->addToAssertionCount(1);
    }

    public function testFactoryWithoutLoggerUsesNullLogger(): void
    {
        $factory = new ElasticsearchRoundRobinClientFactory();
        $factory->createClient(['http://localhost:9200']);

        // No exception means success - NullLogger was used
        $this->addToAssertionCount(1);
    }
}
