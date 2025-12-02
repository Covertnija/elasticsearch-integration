<?php

declare(strict_types=1);

namespace EV\ElasticsearchIntegration\Tests\Factory;

use EV\ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use Elasticsearch\Client;
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
     * Test creating a client with valid hosts.
     */
    public function testCreateClientWithValidHosts(): void
    {
        $hosts = ['http://localhost:9200', 'http://localhost:9201'];
        
        $client = $this->factory->createClient($hosts);
        
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test creating a client with API key.
     */
    public function testCreateClientWithApiKey(): void
    {
        $hosts = ['http://localhost:9200'];
        $apiKey = 'test-api-key';
        
        $client = $this->factory->createClient($hosts, $apiKey);
        
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test creating a client with additional options.
     */
    public function testCreateClientWithAdditionalOptions(): void
    {
        $hosts = ['http://localhost:9200'];
        $options = ['timeout' => 30, 'connectTimeout' => 5];
        
        $client = $this->factory->createClient($hosts, null, $options);
        
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test that creating a client with empty hosts throws an exception.
     */
    public function testCreateClientWithEmptyHostsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one Elasticsearch host must be provided');
        
        $this->factory->createClient([]);
    }

    /**
     * Test factory without logger (uses NullLogger).
     */
    public function testFactoryWithoutLogger(): void
    {
        $factory = new ElasticsearchRoundRobinClientFactory();
        $hosts = ['http://localhost:9200'];
        
        $client = $factory->createClient($hosts);
        
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test creating client with single host.
     */
    public function testCreateClientWithSingleHost(): void
    {
        $hosts = ['http://localhost:9200'];
        
        $client = $this->factory->createClient($hosts);
        
        $this->assertInstanceOf(Client::class, $client);
    }

    /**
     * Test creating client with multiple hosts.
     */
    public function testCreateClientWithMultipleHosts(): void
    {
        $hosts = [
            'http://localhost:9200',
            'http://localhost:9201',
            'http://localhost:9202',
        ];
        
        $client = $this->factory->createClient($hosts);
        
        $this->assertInstanceOf(Client::class, $client);
    }
}
