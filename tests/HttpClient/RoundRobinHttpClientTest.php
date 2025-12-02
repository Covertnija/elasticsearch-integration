<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\HttpClient;

use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for RoundRobinHttpClient.
 */
final class RoundRobinHttpClientTest extends TestCase
{
    /**
     * Test client initialization with valid hosts.
     */
    public function testInitializationWithValidHosts(): void
    {
        $hosts = ['http://localhost:9200', 'http://localhost:9201'];

        $client = new RoundRobinHttpClient($hosts, new NullLogger());

        self::assertSame($hosts, $client->getHosts());
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    /**
     * Test that initialization with empty hosts throws an exception.
     */
    public function testInitializationWithEmptyHostsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one host must be provided');

        new RoundRobinHttpClient([], new NullLogger());
    }

    /**
     * Test reset functionality.
     */
    public function testReset(): void
    {
        $hosts = ['http://localhost:9200', 'http://localhost:9201'];

        $client = new RoundRobinHttpClient($hosts, new NullLogger());
        $client->reset();

        self::assertSame(0, $client->getCurrentHostIndex());
    }

    /**
     * Test client without logger (uses NullLogger by default).
     */
    public function testClientWithoutLogger(): void
    {
        $hosts = ['http://localhost:9200'];

        $client = new RoundRobinHttpClient($hosts);

        self::assertSame($hosts, $client->getHosts());
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    /**
     * Test getHosts returns copy of hosts array.
     */
    public function testGetHostsReturnsCopy(): void
    {
        $hosts = ['http://localhost:9200', 'http://localhost:9201'];

        $client = new RoundRobinHttpClient($hosts, new NullLogger());
        $returnedHosts = $client->getHosts();

        // Modify returned array
        $returnedHosts[] = 'http://localhost:9202';

        // Original should be unchanged
        self::assertSame($hosts, $client->getHosts());
        self::assertNotSame($hosts, $returnedHosts);
    }
}
