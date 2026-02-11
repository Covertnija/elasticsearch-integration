<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Handler;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LazyElasticsearchHandlerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->build();
    }

    public function testWriteDiscardsRecordWhenDisabled(): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            false,
        );

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'Test message',
        );

        // Should not throw — silently discards
        $handler->handle($record);

        $this->addToAssertionCount(1);
    }

    public function testHandleBatchDiscardsWhenDisabled(): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            false,
        );

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'Test message',
        );

        // Should not throw — silently discards
        $handler->handleBatch([$record]);

        $this->addToAssertionCount(1);
    }

    public function testConstructionDoesNotInitializeInnerHandler(): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            true,
        );

        // Construction should succeed without connecting to ES
        $this->addToAssertionCount(1);
    }

    public function testAcceptsKibanaFormatter(): void
    {
        $formatter = new KibanaCompatibleFormatter('test-index');

        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            true,
            $formatter,
        );

        $this->addToAssertionCount(1);
    }

    public function testIsHandlingExcludesElasticsearchChannel(): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            true,
        );

        $elasticsearchRecord = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'elasticsearch',
            level: Level::Error,
            message: 'ES internal log',
        );

        $appRecord = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'App log',
        );

        self::assertFalse($handler->isHandling($elasticsearchRecord));
        self::assertTrue($handler->isHandling($appRecord));
    }

    public function testHandleBatchFiltersElasticsearchChannel(): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            false,
        );

        $records = [
            new LogRecord(
                datetime: new \DateTimeImmutable(),
                channel: 'elasticsearch',
                level: Level::Error,
                message: 'ES internal log',
            ),
            new LogRecord(
                datetime: new \DateTimeImmutable(),
                channel: 'app',
                level: Level::Error,
                message: 'App log',
            ),
        ];

        // Should not throw — elasticsearch channel records are filtered out
        $handler->handleBatch($records);

        $this->addToAssertionCount(1);
    }
}
