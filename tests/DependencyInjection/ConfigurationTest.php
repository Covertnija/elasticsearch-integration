<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\DependencyInjection;

use ElasticsearchIntegration\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Unit tests for Configuration.
 */
final class ConfigurationTest extends TestCase
{
    private Processor $processor;

    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    /**
     * Test default configuration values.
     */
    public function testDefaultConfiguration(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [],
        );

        self::assertTrue($config['enabled']);
        self::assertSame(['http://localhost:9200'], $config['hosts']);
        self::assertNull($config['api_key']);
        self::assertSame('app-logs', $config['index']);
        self::assertSame([], $config['client_options']);
    }

    /**
     * Test custom configuration values.
     */
    public function testCustomConfiguration(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [[
                'enabled' => false,
                'hosts' => ['http://es1:9200', 'http://es2:9200'],
                'api_key' => 'test-api-key',
                'index' => 'custom-index',
                'client_options' => [
                    'retries' => 3,
                    'sslVerification' => false,
                ],
            ]],
        );

        self::assertFalse($config['enabled']);
        self::assertSame(['http://es1:9200', 'http://es2:9200'], $config['hosts']);
        self::assertSame('test-api-key', $config['api_key']);
        self::assertSame('custom-index', $config['index']);
        self::assertSame(['retries' => 3, 'sslVerification' => false], $config['client_options']);
    }

    /**
     * Test that hosts require at least one element.
     */
    public function testHostsRequireAtLeastOneElement(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration(
            $this->configuration,
            [[
                'hosts' => [],
            ]],
        );
    }

    /**
     * Test that empty host values are not allowed.
     */
    public function testEmptyHostValueNotAllowed(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration(
            $this->configuration,
            [[
                'hosts' => [''],
            ]],
        );
    }
}
