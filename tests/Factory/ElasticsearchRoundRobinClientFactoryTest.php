<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Factory;

use Elastic\Elasticsearch\Exception\AuthenticationException;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for ElasticsearchRoundRobinClientFactory.
 */
final class ElasticsearchRoundRobinClientFactoryTest extends TestCase
{
    private ElasticsearchRoundRobinClientFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ElasticsearchRoundRobinClientFactory(new NullLogger());
    }

    /**
     * Test creating a client with API key.
     *
     * @throws AuthenticationException
     */
    public function testCreateClientWithApiKey(): void
    {
        $hosts = ['http://localhost:9200'];
        $apiKey = 'test-api-key';

        $this->factory->createClient($hosts, $apiKey);

        // Test passes if no exception is thrown
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test that creating a client with empty hosts throws an exception.
     *
     * @throws AuthenticationException
     */
    public function testCreateClientWithEmptyHostsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one Elasticsearch host must be provided');

        $this->factory->createClient([]);
    }

    /**
     * Test factory without logger (uses NullLogger by default).
     *
     * @throws AuthenticationException
     */
    public function testFactoryWithoutLogger(): void
    {
        $factory = new ElasticsearchRoundRobinClientFactory();
        $hosts = ['http://localhost:9200'];

        $factory->createClient($hosts);

        // Test passes if no exception is thrown
        $this->expectNotToPerformAssertions();
    }
}
