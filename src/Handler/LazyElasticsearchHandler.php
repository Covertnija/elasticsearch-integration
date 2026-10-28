<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Handler;

use Closure;
use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Exception\RejectedRecordsException;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\ElasticsearchHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lazy-initializing Elasticsearch handler that defers client resolution and
 * ElasticsearchHandler construction until the first record is sent.
 *
 * The client may be passed as a closure (`Closure(): Client`) so it is only
 * resolved when the first record is actually sent — no connection setup
 * happens at construction or during container compilation.
 *
 * The enabled flag silently discards all records when set, and records from
 * the `elasticsearch` channel are always excluded to prevent circular
 * logging. Records below the configured level are filtered out (MonologBundle
 * ignores `level` for `type: service` handlers).
 *
 * The handler is fail-safe: when a send fails, the exception is logged and
 * suppressed, and sending is paused for the configured retry window so that a
 * broken Elasticsearch can never break the application — even when records
 * are flushed from BufferHandler::close() or reset(). The suspension is also
 * written to the configured PSR-6 cache pool so it is shared across
 * processes (e.g. PHP-FPM workers). Per-item bulk errors are classified:
 * data problems (e.g. mapping errors) produce a warning and do not suspend
 * sending, while back-pressure items (status 429, 5xx or
 * cluster_block_exception) suspend sending like a transport failure.
 */
class LazyElasticsearchHandler extends AbstractProcessingHandler
{
    private const EXCLUDED_CHANNEL = 'elasticsearch';

    private const SUSPENSION_CACHE_KEY = 'elasticsearch_integration.suspended_until';

    private ?ElasticsearchHandler $innerHandler = null;

    private ?int $suspendedUntil = null;

    private bool $sending = false;

    private readonly bool $enabled;

    /**
     * @param Client|Closure(): Client $client
     * @param array{index?: string, type?: string, ignore_error?: bool, op_type?: 'create'|'index'} $options
     */
    public function __construct(
        private readonly Client|Closure $client,
        private readonly array $options,
        bool|string $enabled,
        private readonly ?KibanaCompatibleFormatter $kibanaFormatter = null,
        private readonly ?LoggerInterface $logger = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly int $retryAfterSeconds = 300,
        private readonly ?ClockInterface $clock = null,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
        $this->enabled = filter_var($enabled, \FILTER_VALIDATE_BOOL);

        parent::__construct($level, $bubble);
    }

    public function isHandling(LogRecord $record): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($record->channel === self::EXCLUDED_CHANNEL) {
            return false;
        }

        return parent::isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        $record = $this->processRecord($record);
        $this->sendRecords([$record]);

        return $this->bubble === false;
    }

    public function handleBatch(array $records): void
    {
        if (! $this->enabled) {
            return;
        }

        $records = array_map(
            $this->processRecord(...),
            array_filter($records, $this->isHandling(...)),
        );

        $this->sendRecords($records);
    }

    public function getFormatter(): FormatterInterface
    {
        return $this->kibanaFormatter ?? $this->innerHandler?->getFormatter() ?? parent::getFormatter();
    }

    protected function write(LogRecord $record): void
    {
        $this->sendRecords([$record]);
    }

    /**
     * @param array<LogRecord> $records
     */
    private function sendRecords(array $records): void
    {
        if ($records === []) {
            return;
        }

        $this->send(function () use ($records): void {
            $this->getInnerHandler()->handleBatch(array_values($records));
        });
    }

    /**
     * Runs a send operation while suppressing failures.
     *
     * Re-entrant calls (e.g. the Elasticsearch client's own error log looping
     * back through consumer handlers) and calls made while the handler is
     * suspended after a previous failure are dropped silently.
     *
     * @param callable(): mixed $operation
     */
    private function send(callable $operation): void
    {
        if ($this->sending || $this->isSuspended()) {
            return;
        }

        $this->sending = true;

        try {
            $operation();
        } catch (Throwable $e) {
            if ($this->isRejection($e)) {
                $this->logger?->warning('Elasticsearch rejected one or more log records', [
                    'exception' => $e,
                ]);

                return;
            }

            $this->suspendedUntil = $this->now() + $this->retryAfterSeconds;
            $this->storeSuspension();
            $this->logger?->error(
                sprintf('Failed to send logs to Elasticsearch, skipping it for %d seconds.', $this->retryAfterSeconds),
                [
                    'exception' => $e,
                    'retry_after_seconds' => $this->retryAfterSeconds,
                ],
            );
        } finally {
            $this->sending = false;
        }
    }

    /**
     * Whether sending is currently suspended, either by this instance or by
     * another process via the shared cache pool.
     */
    private function isSuspended(): bool
    {
        $now = $this->now();

        if ($this->suspendedUntil !== null && $this->suspendedUntil > $now) {
            return true;
        }

        if ($this->cache === null) {
            return false;
        }

        try {
            $item = $this->cache->getItem(self::SUSPENSION_CACHE_KEY);
            $until = $item->isHit() ? $item->get() : null;

            if (is_int($until) && $until > $now) {
                $this->suspendedUntil = $until;

                return true;
            }
        } catch (Throwable) {
            // The cache must never break logging
        }

        return false;
    }

    /**
     * Persists the suspension to the shared cache pool so other processes
     * (e.g. PHP-FPM workers) skip sending for the same window.
     */
    private function storeSuspension(): void
    {
        if ($this->cache === null || $this->retryAfterSeconds <= 0 || $this->suspendedUntil === null) {
            return;
        }

        try {
            $item = $this->cache->getItem(self::SUSPENSION_CACHE_KEY);
            $item->set($this->suspendedUntil);
            $item->expiresAfter($this->retryAfterSeconds);
            $this->cache->save($item);
        } catch (Throwable) {
            // The cache must never break logging
        }
    }

    /**
     * Whether the exception chain describes rejected documents (per-item bulk
     * data errors) rather than a transport-level or back-pressure failure.
     * Rejected documents are logged once as a warning but never suspend
     * sending.
     */
    private function isRejection(Throwable $e): bool
    {
        do {
            if ($e instanceof RejectedRecordsException) {
                return true;
            }
        } while ($e = $e->getPrevious());

        return false;
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }

    /**
     * Lazily resolves the client and initializes the inner ElasticsearchHandler.
     */
    private function getInnerHandler(): ElasticsearchHandler
    {
        if ($this->innerHandler !== null) {
            return $this->innerHandler;
        }

        $client = $this->client instanceof Closure ? ($this->client)() : $this->client;

        $this->innerHandler = new BulkResponseAwareElasticsearchHandler($client, $this->options);

        if ($this->kibanaFormatter !== null) {
            $this->innerHandler->setFormatter($this->kibanaFormatter);
        }

        return $this->innerHandler;
    }
}
