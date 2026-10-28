<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Handler;

use DateTimeImmutable;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
use Monolog\Handler\BufferHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class LazyElasticsearchHandlerTest extends TestCase
{
    private Client $client;

    private DateTimeImmutable $now;

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
            datetime: new DateTimeImmutable(),
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
            datetime: new DateTimeImmutable(),
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
            datetime: new DateTimeImmutable(),
            channel: 'elasticsearch',
            level: Level::Error,
            message: 'ES internal log',
        );

        $appRecord = new LogRecord(
            datetime: new DateTimeImmutable(),
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
                datetime: new DateTimeImmutable(),
                channel: 'elasticsearch',
                level: Level::Error,
                message: 'ES internal log',
            ),
            new LogRecord(
                datetime: new DateTimeImmutable(),
                channel: 'app',
                level: Level::Error,
                message: 'App log',
            ),
        ];

        // Should not throw — elasticsearch channel records are filtered out
        $handler->handleBatch($records);

        $this->addToAssertionCount(1);
    }

    public function testHandleBatchLogsErrorAndDoesNotThrowWhenElasticsearchFails(): void
    {
        $clock = $this->createClock();
        $exception = new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to send logs to Elasticsearch, skipping it for 300 seconds.',
                self::callback(
                    static fn (array $context): bool => $context['exception'] instanceof Throwable
                        && $context['retry_after_seconds'] === 300,
                ),
            );

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        // Should not throw — failure is logged and suppressed
        $handler->handleBatch([$this->createRecord()]);
    }

    public function testHandleBatchSkipsSendingWhileSuspended(): void
    {
        $clock = $this->createClock();
        $exception = new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        // Only the first batch should reach Elasticsearch; the second one is
        // dropped because the handler is suspended within the retry window
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        $handler->handleBatch([$this->createRecord()]);

        $this->now = $this->now->modify('+299 seconds');
        $handler->handleBatch([$this->createRecord()]);
    }

    public function testHandleBatchRetriesAfterRetryWindow(): void
    {
        $clock = $this->createClock();
        $exception = new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        $handler->handleBatch([$this->createRecord()]);

        $this->now = $this->now->modify('+301 seconds');
        $handler->handleBatch([$this->createRecord()]);
    }

    public function testWriteDoesNotThrowWhenElasticsearchFails(): void
    {
        $clock = $this->createClock();
        $exception = new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        // Should not throw even though the bulk request fails
        $handler->handle($this->createRecord());
    }

    public function testBufferHandlerCloseDoesNotThrowWhenElasticsearchFails(): void
    {
        $clock = $this->createClock();
        $exception = new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        $buffer = new BufferHandler($handler);
        $buffer->handle($this->createRecord());

        // Flushes the buffer — the ES failure must not escape from close()
        $buffer->close();
    }

    public function testBufferHandlerResetDoesNotThrowWhenElasticsearchFails(): void
    {
        $clock = $this->createClock();
        $exception = new class('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        $buffer = new BufferHandler($handler);
        $buffer->handle($this->createRecord());

        // Flushes the buffer — the ES failure must not escape from reset()
        $buffer->reset();
    }

    public function testReentrantLogDuringSendIsDropped(): void
    {
        $clock = $this->createClock();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        // The PSR-18 client logs back into the handler while sending;
        // the re-entrant record must be dropped instead of looping
        /** @var LazyElasticsearchHandler|null $handler */
        $handler = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$handler): Response {
                self::assertInstanceOf(LazyElasticsearchHandler::class, $handler);
                $handler->handle($this->createRecord());

                return $this->createBulkResponse();
            });

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        $handler->handleBatch([$this->createRecord()]);
    }

    public function testBulkRequestUsesConfiguredOpType(): void
    {
        $clock = $this->createClock();
        $capturedBody = null;

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) use (&$capturedBody): Response {
                $capturedBody = (string) $request->getBody();

                return $this->createBulkResponse();
            });

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index', 'op_type' => 'create'],
            true,
            clock: $clock,
        );

        $handler->handle($this->createRecord());

        self::assertNotNull($capturedBody);
        $lines = array_values(array_filter(explode("\n", $capturedBody), static fn (string $line): bool => $line !== ''));
        $action = json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($action);
        self::assertArrayHasKey('create', $action);
    }

    private function createClock(): ClockInterface
    {
        $this->now = new DateTimeImmutable('2025-01-01 00:00:00');

        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(fn (): DateTimeImmutable => $this->now);

        return $clock;
    }

    private function createClient(ClientInterface $httpClient): Client
    {
        return ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->setHttpClient($httpClient)
            ->build();
    }

    private function createBulkResponse(): Response
    {
        return new Response(
            200,
            [
                'X-Elastic-Product' => 'Elasticsearch',
                'Content-Type' => 'application/json',
            ],
            '{"errors":false,"items":[]}',
        );
    }

    private function createRecord(string $channel = 'app'): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: $channel,
            level: Level::Error,
            message: 'Test message',
        );
    }
}
