<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Handler;

use ArrayObject;
use DateTimeImmutable;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use ElasticsearchIntegration\Exception\RejectedRecordsException;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
use Monolog\Handler\BufferHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
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

    public function testClientClosureIsNotInvokedAtConstruction(): void
    {
        $invocations = new ArrayObject();
        $clientFactory = function () use ($invocations): Client {
            $invocations->append(true);

            return $this->client;
        };

        new LazyElasticsearchHandler(
            $clientFactory,
            ['index' => 'test-index'],
            true,
        );

        self::assertCount(0, $invocations);
    }

    public function testClientClosureIsNotInvokedWhenDisabled(): void
    {
        $invocations = new ArrayObject();
        $clientFactory = function () use ($invocations): Client {
            $invocations->append(true);

            return $this->client;
        };

        $handler = new LazyElasticsearchHandler(
            $clientFactory,
            ['index' => 'test-index'],
            false,
        );

        $handler->handle($this->createRecord());
        $handler->handleBatch([$this->createRecord()]);

        self::assertCount(0, $invocations);
    }

    public function testClientClosureIsInvokedOnceOnFirstSend(): void
    {
        $invocations = new ArrayObject();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->createBulkResponse());

        $clientFactory = function () use ($invocations, $httpClient): Client {
            $invocations->append(true);

            return $this->createClient($httpClient);
        };

        $handler = new LazyElasticsearchHandler(
            $clientFactory,
            ['index' => 'test-index'],
            true,
        );

        $handler->handle($this->createRecord());
        self::assertCount(1, $invocations);

        $handler->handle($this->createRecord());
        self::assertCount(1, $invocations);
    }

    public function testThrowingClientClosureLogsErrorOnceAndSuspends(): void
    {
        $clock = $this->createClock();
        $invocations = new ArrayObject();

        $clientFactory = static function () use ($invocations): Client {
            $invocations->append(true);

            throw new RuntimeException('Client resolution failed');
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to send logs to Elasticsearch, skipping it for 300 seconds.',
                self::callback(static fn (array $context): bool => $context['exception'] instanceof Throwable),
            );

        $handler = new LazyElasticsearchHandler(
            $clientFactory,
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        $handler->handle($this->createRecord());
        self::assertCount(1, $invocations);

        // Suspended within the retry window — the closure is not invoked again
        $handler->handle($this->createRecord());
        self::assertCount(1, $invocations);
    }

    public function testHandleBatchFiltersRecordsBelowHandlerLevel(): void
    {
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
            ['index' => 'test-index'],
            true,
            level: Level::Info,
        );

        $handler->handleBatch([
            new LogRecord(
                datetime: new DateTimeImmutable(),
                channel: 'app',
                level: Level::Debug,
                message: 'Debug message',
            ),
            new LogRecord(
                datetime: new DateTimeImmutable(),
                channel: 'app',
                level: Level::Error,
                message: 'Error message',
            ),
        ]);

        self::assertNotNull($capturedBody);
        self::assertStringNotContainsString('Debug message', $capturedBody);
        self::assertStringContainsString('Error message', $capturedBody);
    }

    public function testProcessorOutputAppearsInBulkBodyForHandle(): void
    {
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
            ['index' => 'test-index'],
            true,
        );
        $handler->pushProcessor(static function (LogRecord $record): LogRecord {
            $record->extra['processor_marker'] = 'handled-record';

            return $record;
        });

        $handler->handle($this->createRecord());

        self::assertNotNull($capturedBody);
        self::assertStringContainsString('handled-record', $capturedBody);
    }

    public function testProcessorOutputAppearsInBulkBodyForHandleBatch(): void
    {
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
            ['index' => 'test-index'],
            true,
        );
        $handler->pushProcessor(static function (LogRecord $record): LogRecord {
            $record->extra['processor_marker'] = 'batched-record';

            return $record;
        });

        $handler->handleBatch([$this->createRecord()]);

        self::assertNotNull($capturedBody);
        self::assertStringContainsString('batched-record', $capturedBody);
    }

    public function testHandleReturnsTrueOnlyWhenNotBubbling(): void
    {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->createBulkResponse());

        $bubblingHandler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            bubble: true,
        );

        $nonBubblingHandler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            bubble: false,
        );

        self::assertFalse($bubblingHandler->handle($this->createRecord()));
        self::assertTrue($nonBubblingHandler->handle($this->createRecord()));
    }

    public function testHandleReturnsFalseWhenNotHandling(): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            false,
        );

        self::assertFalse($handler->handle($this->createRecord()));
    }

    public function testIsHandlingReturnsFalseWhenDisabled(): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            false,
        );

        self::assertFalse($handler->isHandling($this->createRecord()));
    }

    public function testGetFormatterReturnsKibanaFormatterWithoutResolvingClient(): void
    {
        $invocations = new ArrayObject();
        $clientFactory = function () use ($invocations): Client {
            $invocations->append(true);

            return $this->client;
        };

        $formatter = new KibanaCompatibleFormatter('test-index');

        $handler = new LazyElasticsearchHandler(
            $clientFactory,
            ['index' => 'test-index'],
            true,
            $formatter,
        );

        self::assertSame($formatter, $handler->getFormatter());
        self::assertCount(0, $invocations);
    }

    public function testHandleSendsExactlyOneRequest(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->createBulkResponse());

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
        );

        $handler->handle($this->createRecord());
    }

    /**
     * @return iterable<string, array{bool|string, bool}>
     */
    public static function enabledNormalizationProvider(): iterable
    {
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];
        yield 'string true' => ['true', true];
        yield 'string 1' => ['1', true];
        yield 'string on' => ['on', true];
        yield 'string yes' => ['yes', true];
        yield 'string false' => ['false', false];
        yield 'string 0' => ['0', false];
        yield 'string off' => ['off', false];
        yield 'string no' => ['no', false];
        yield 'empty string' => ['', false];
    }

    #[DataProvider('enabledNormalizationProvider')]
    public function testEnabledStringNormalization(bool|string $enabled, bool $expected): void
    {
        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            $enabled,
        );

        self::assertSame($expected, $handler->isHandling($this->createRecord()));
    }

    public function testConstructWithInvalidLevelThrows(): void
    {
        $this->expectException(\Psr\Log\InvalidArgumentException::class);

        new LazyElasticsearchHandler(
            $this->client,
            ['index' => 'test-index'],
            true,
            // @phpstan-ignore-next-line intentionally invalid to test runtime validation
            level: 'verbose',
        );
    }

    public function testRejectedDocumentsLogWarningAndDoNotSuspend(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturn($this->createRejectionResponse('index'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('warning')
            ->with(
                'Elasticsearch rejected one or more log records',
                self::callback(
                    static function (array $context): bool {
                        $e = $context['exception'] ?? null;

                        while ($e !== null) {
                            if ($e instanceof RejectedRecordsException) {
                                return $e->errorType === 'mapper_parsing_exception';
                            }
                            $e = $e->getPrevious();
                        }

                        return false;
                    },
                ),
            );
        $logger->expects($this->never())->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
        );

        $handler->handle($this->createRecord());
        $handler->handle($this->createRecord());
    }

    public function testRejectedDocumentsWithCreateOpTypeDoNotSuspend(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturn($this->createRejectionResponse('create'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('warning')
            ->with(
                'Elasticsearch rejected one or more log records',
                self::callback(
                    static function (array $context): bool {
                        $e = $context['exception'] ?? null;

                        while ($e !== null) {
                            if ($e instanceof RejectedRecordsException) {
                                return $e->errorType === 'mapper_parsing_exception';
                            }
                            $e = $e->getPrevious();
                        }

                        return false;
                    },
                ),
            );
        $logger->expects($this->never())->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index', 'op_type' => 'create'],
            true,
            logger: $logger,
        );

        $handler->handle($this->createRecord());
        $handler->handle($this->createRecord());
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function backPressureItemsProvider(): iterable
    {
        yield '429 rejected execution' => [[['index' => ['status' => 429, 'error' => ['type' => 'es_rejected_execution_exception', 'reason' => 'rejected execution']]]]];
        yield '403 cluster block' => [[['index' => ['status' => 403, 'error' => ['type' => 'cluster_block_exception', 'reason' => 'index is read-only']]]]];
        yield '503 unavailable shard' => [[['index' => ['status' => 503, 'error' => ['type' => 'unavailable_shard_exception', 'reason' => 'shard unavailable']]]]];
        yield 'mixed 400 and 429' => [[
            ['index' => ['status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'failed to parse']]],
            ['index' => ['status' => 429, 'error' => ['type' => 'es_rejected_execution_exception', 'reason' => 'rejected execution']]],
        ]];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    #[DataProvider('backPressureItemsProvider')]
    public function testBackPressureItemsSuspendSending(array $items): void
    {
        $clock = $this->createClock();

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn($this->createBulkErrorsResponse($items));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $logger->expects($this->never())->method('warning');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
            retryAfterSeconds: 300,
            clock: $clock,
        );

        $handler->handle($this->createRecord());
        $handler->handle($this->createRecord());
    }

    public function testRejectedCountReflectsRejectedItems(): void
    {
        $items = [
            ['index' => ['status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'failed to parse']]],
            ['index' => ['status' => 200]],
            ['index' => ['status' => 400, 'error' => ['type' => 'illegal_argument_exception', 'reason' => 'bad value']]],
        ];

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnCallback(function () use ($items): Response {
                return $this->createBulkErrorsResponse($items);
            });

        $captured = null;
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(static function (string $message, array $context) use (&$captured): void {
                $e = $context['exception'] ?? null;

                while ($e !== null && ! $e instanceof RejectedRecordsException) {
                    $e = $e->getPrevious();
                }
                $captured = $e;
            });

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
        );

        $handler->handle($this->createRecord());

        self::assertInstanceOf(RejectedRecordsException::class, $captured);
        self::assertSame(2, $captured->rejectedCount);
        self::assertSame('mapper_parsing_exception', $captured->errorType);
    }

    public function testBulkErrorsWithoutItemDetailsWarnAndDoNotSuspend(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturn(new Response(
                200,
                [
                    'X-Elastic-Product' => 'Elasticsearch',
                    'Content-Type' => 'application/json',
                ],
                '{"took":1,"errors":true,"items":[]}',
            ));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');
        $logger->expects($this->never())->method('error');

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: $logger,
        );

        $handler->handle($this->createRecord());
        $handler->handle($this->createRecord());
    }

    public function testHttpErrorResponseSuspendsSending(): void
    {
        $clock = $this->createClock();

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(new Response(
                503,
                [
                    'X-Elastic-Product' => 'Elasticsearch',
                    'Content-Type' => 'application/json',
                ],
                '{"error":{"type":"unavailable_shard_exception"},"status":503}',
            ));

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

        $handler->handle($this->createRecord());
        $handler->handle($this->createRecord());
    }

    public function testSuspensionIsSharedAcrossHandlersViaCache(): void
    {
        $clock = $this->createClock();
        $cache = new ArrayAdapter();
        $exception = $this->createNetworkException();

        $failingHttpClient = self::createStub(ClientInterface::class);
        $failingHttpClient->method('sendRequest')->willThrowException($exception);

        $firstHandler = new LazyElasticsearchHandler(
            $this->createClient($failingHttpClient),
            ['index' => 'test-index'],
            true,
            logger: self::createStub(LoggerInterface::class),
            retryAfterSeconds: 300,
            clock: $clock,
            cache: $cache,
        );

        $firstHandler->handle($this->createRecord());

        $invocations = new ArrayObject();
        $clientFactory = function () use ($invocations): Client {
            $invocations->append(true);

            return $this->client;
        };

        $secondHandler = new LazyElasticsearchHandler(
            $clientFactory,
            ['index' => 'test-index'],
            true,
            retryAfterSeconds: 300,
            clock: $clock,
            cache: $cache,
        );

        $secondHandler->handle($this->createRecord());
        self::assertCount(0, $invocations);

        $this->now = $this->now->modify('+301 seconds');
        $secondHandler->handle($this->createRecord());
        self::assertCount(1, $invocations);
    }

    public function testThrowingCachePoolDoesNotBreakLogging(): void
    {
        $cache = new class extends ArrayAdapter {
            public function getItem(mixed $key): CacheItem
            {
                throw new RuntimeException('Cache backend is down');
            }

            public function save(CacheItemInterface $item): bool
            {
                throw new RuntimeException('Cache backend is down');
            }
        };

        $exception = $this->createNetworkException();
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($exception);

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: self::createStub(LoggerInterface::class),
            cache: $cache,
        );

        // The send fails AND the cache fails — neither exception must escape
        $handler->handle($this->createRecord());
        $handler->handle($this->createRecord());

        $this->addToAssertionCount(1);
    }

    public function testZeroRetryAfterSecondsWritesNothingToCache(): void
    {
        $cache = new ArrayAdapter();
        $exception = $this->createNetworkException();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willThrowException($exception);

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: self::createStub(LoggerInterface::class),
            retryAfterSeconds: 0,
            cache: $cache,
        );

        $handler->handle($this->createRecord());

        self::assertFalse($cache->getItem('elasticsearch_integration.suspended_until')->isHit());
    }

    public function testRejectionWritesNothingToCache(): void
    {
        $cache = new ArrayAdapter();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($this->createRejectionResponse('index'));

        $handler = new LazyElasticsearchHandler(
            $this->createClient($httpClient),
            ['index' => 'test-index'],
            true,
            logger: self::createStub(LoggerInterface::class),
            cache: $cache,
        );

        $handler->handle($this->createRecord());

        self::assertFalse($cache->getItem('elasticsearch_integration.suspended_until')->isHit());
    }

    private function createRejectionResponse(string $opType): Response
    {
        return $this->createBulkErrorsResponse([
            [$opType => ['status' => 400, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'failed to parse']]],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function createBulkErrorsResponse(array $items): Response
    {
        return new Response(
            200,
            [
                'X-Elastic-Product' => 'Elasticsearch',
                'Content-Type' => 'application/json',
            ],
            (string) json_encode(['took' => 1, 'errors' => true, 'items' => $items]),
        );
    }

    private function createNetworkException(): NetworkExceptionInterface
    {
        return new class('Connection refused') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new Request('GET', 'http://localhost:9200');
            }
        };
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
