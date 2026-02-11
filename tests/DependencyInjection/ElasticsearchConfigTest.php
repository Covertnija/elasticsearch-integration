<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\DependencyInjection;

use ElasticsearchIntegration\DependencyInjection\ElasticsearchConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ElasticsearchConfigTest extends TestCase
{
    public function testFromArray(): void
    {
        $config = ElasticsearchConfig::fromArray([
            'enabled' => true,
            'hosts' => ['http://es1:9200', 'http://es2:9200'],
            'api_key' => 'test-key',
            'index' => 'test-index',
            'client_options' => ['retries' => 3],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['http://es1:9200', 'http://es2:9200'], $config->hosts);
        self::assertSame('test-key', $config->apiKey);
        self::assertSame('test-index', $config->index);
        self::assertSame(['retries' => 3], $config->clientOptions);
    }

    public function testFromArrayWithNullApiKey(): void
    {
        $config = ElasticsearchConfig::fromArray([
            'enabled' => false,
            'hosts' => ['http://localhost:9200'],
            'api_key' => null,
            'index' => 'app-logs',
            'client_options' => [],
        ]);

        self::assertFalse($config->enabled);
        self::assertNull($config->apiKey);
        self::assertSame([], $config->clientOptions);
    }

    public function testDtoIsReadonly(): void
    {
        $reflection = new ReflectionClass(ElasticsearchConfig::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
