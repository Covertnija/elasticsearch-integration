<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\DependencyInjection;

use ElasticsearchIntegration\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    private Processor $processor;

    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

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

    public function testStringHostNormalizedToArray(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [['hosts' => 'http://single-host:9200']],
        );

        self::assertSame(['http://single-host:9200'], $config['hosts']);
    }

    public function testNestedArrayHostsAcceptedByConfigTree(): void
    {
        $config = $this->processor->processConfiguration(
            $this->configuration,
            [['hosts' => [['http://es1:9200', 'http://es2:9200']]]],
        );

        self::assertSame([['http://es1:9200', 'http://es2:9200']], $config['hosts']);
    }
}
