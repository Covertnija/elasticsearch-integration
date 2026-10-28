<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Integration;

use DateTimeImmutable;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use ElasticsearchIntegration\Exception\RejectedRecordsException;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Throwable;

/**
 * Live integration tests against a real Elasticsearch instance.
 *
 * These tests only run when the ELASTICSEARCH_LIVE_TEST_HOSTS environment
 * variable is set (comma-separated list of host URLs).
 */
#[Group('live')]
final class LiveElasticsearchTest extends TestCase
{
    private Client $client;

    /**
     * @var list<string>
     */
    private array $createdIndices = [];

    protected function setUp(): void
    {
        $hosts = self::liveHosts();

        if ($hosts === []) {
            self::markTestSkipped('Set ELASTICSEARCH_LIVE_TEST_HOSTS to run live Elasticsearch tests');
        }

        $this->client = (new ElasticsearchRoundRobinClientFactory(new NullLogger()))
            ->createClient($hosts, null, ['retries' => 2, 'timeout' => 10.0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIndices as $index) {
            try {
                $this->client->indices()->delete(['index' => $index]);
            } catch (Throwable) {
                // Best effort cleanup
            }
        }

        $this->createdIndices = [];
    }

    public function testRecordIsIndexedInElasticsearch(): void
    {
        $index = 'es-integration-test-' . bin2hex(random_bytes(6));
        $this->createdIndices[] = $index;

        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => $index],
            true,
            new KibanaCompatibleFormatter($index),
        );

        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'live-test',
            level: Level::Info,
            message: 'Live integration test message',
        ));

        $this->client->indices()->refresh(['index' => $index]);

        $response = $this->client->search([
            'index' => $index,
            'body' => ['query' => ['match_all' => new stdClass()]],
        ]);
        self::assertInstanceOf(Elasticsearch::class, $response);
        $response = $response->asArray();

        self::assertSame(1, $response['hits']['total']['value']);

        $source = $response['hits']['hits'][0]['_source'];
        self::assertSame('Live integration test message', $source['message']);
        self::assertArrayHasKey('@timestamp', $source);
    }

    /**
     * @return iterable<string, array{'index'|'create'}>
     */
    public static function opTypeProvider(): iterable
    {
        yield 'index' => ['index'];
        yield 'create' => ['create'];
    }

    /**
     * @param 'index'|'create' $opType
     */
    #[DataProvider('opTypeProvider')]
    public function testRejectedDocumentWarnsWithoutSuspending(string $opType): void
    {
        $index = 'es-integration-test-' . bin2hex(random_bytes(6));
        $this->createdIndices[] = $index;

        // Every field the KibanaCompatibleFormatter produces must be mapped,
        // so only the unmapped context field below triggers a rejection
        $this->client->indices()->create([
            'index' => $index,
            'body' => [
                'mappings' => [
                    'dynamic' => 'strict',
                    'properties' => [
                        '@timestamp' => ['type' => 'date'],
                        'message' => ['type' => 'text'],
                        'level' => ['type' => 'integer'],
                        'level_name' => ['type' => 'keyword'],
                        'channel' => ['type' => 'keyword'],
                        'context' => ['type' => 'object'],
                        'extra' => ['type' => 'object'],
                    ],
                ],
            ],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Elasticsearch rejected one or more log records',
                self::callback(
                    static function (array $context): bool {
                        $e = $context['exception'] ?? null;

                        while ($e !== null) {
                            if ($e instanceof RejectedRecordsException) {
                                return $e->errorType !== '';
                            }
                            $e = $e->getPrevious();
                        }

                        return false;
                    },
                ),
            );

        $handler = new LazyElasticsearchHandler(
            $this->client,
            ['index' => $index, 'op_type' => $opType],
            true,
            new KibanaCompatibleFormatter($index),
            $logger,
        );

        // The unmapped context field must be rejected by the strict index
        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'live-test',
            level: Level::Info,
            message: 'Rejected document',
            context: ['unmapped_field' => 'value'],
        ));

        // The handler is not suspended: a valid record still goes through
        $handler->handle(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'live-test',
            level: Level::Info,
            message: 'Accepted document',
        ));

        $this->client->indices()->refresh(['index' => $index]);

        $response = $this->client->count(['index' => $index]);
        self::assertInstanceOf(Elasticsearch::class, $response);
        self::assertSame(1, $response->asArray()['count']);
    }

    public function testFailoverToSecondHost(): void
    {
        $hosts = array_merge(['http://127.0.0.1:1'], self::liveHosts());

        $client = (new ElasticsearchRoundRobinClientFactory(new NullLogger()))
            ->createClient($hosts, null, ['retries' => 2, 'timeout' => 10.0]);

        $response = $client->info();

        self::assertInstanceOf(Elasticsearch::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return list<string>
     */
    private static function liveHosts(): array
    {
        $env = getenv('ELASTICSEARCH_LIVE_TEST_HOSTS');

        if ($env === false || trim($env) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $host): string => trim($host),
            explode(',', $env),
        ), static fn (string $host): bool => $host !== ''));
    }
}
