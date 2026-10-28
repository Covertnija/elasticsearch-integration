<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Handler;

use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\ElasticsearchHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lazy-initializing Elasticsearch handler that defers ElasticsearchHandler construction.
 *
 * This handler solves the circular initialization problem during cache:clear where
 * the ElasticsearchHandler's ghost proxy triggers getDefaultFormatter() before
 * $this->options is populated. It also respects the enabled flag to silently
 * discard logs when Elasticsearch is disabled.
 *
 * The handler is fail-safe: when sending to Elasticsearch fails, the exception
 * is logged and suppressed, and sending is paused for the configured retry
 * window so that a broken Elasticsearch can never break the application —
 * even when records are flushed from BufferHandler::close() or reset().
 */
class LazyElasticsearchHandler extends AbstractProcessingHandler
{
    private const EXCLUDED_CHANNEL = 'elasticsearch';

    private ?ElasticsearchHandler $innerHandler = null;

    private bool $initializing = false;

    private ?int $suspendedUntil = null;

    private bool $sending = false;

    /**
     * @param array{index?: string, type?: string, ignore_error?: bool, op_type?: 'create'|'index'} $options
     */
    public function __construct(
        private readonly Client $client,
        private readonly array $options,
        private readonly bool $enabled,
        private readonly ?KibanaCompatibleFormatter $kibanaFormatter = null,
        private readonly ?LoggerInterface $logger = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly int $retryAfterSeconds = 300,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct($level, $bubble);
    }

    public function isHandling(LogRecord $record): bool
    {
        if ($record->channel === self::EXCLUDED_CHANNEL) {
            return false;
        }

        return parent::isHandling($record);
    }

    protected function write(LogRecord $record): void
    {
        if (! $this->enabled) {
            return;
        }

        $handler = $this->getInnerHandler();
        if ($handler === null) {
            return;
        }

        $this->send(static fn (): bool => $handler->handle($record));
    }

    public function handleBatch(array $records): void
    {
        if (! $this->enabled) {
            return;
        }

        $records = array_filter(
            $records,
            static fn (LogRecord $record): bool => $record->channel !== self::EXCLUDED_CHANNEL,
        );

        if ($records === []) {
            return;
        }

        $handler = $this->getInnerHandler();
        if ($handler === null) {
            return;
        }

        $this->send(static function () use ($handler, $records): void {
            $handler->handleBatch($records);
        });
    }

    public function getFormatter(): FormatterInterface
    {
        $handler = $this->getInnerHandler();
        if ($handler !== null) {
            return $handler->getFormatter();
        }

        return parent::getFormatter();
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
        if ($this->sending || ($this->suspendedUntil !== null && $this->now() < $this->suspendedUntil)) {
            return;
        }

        $this->sending = true;

        try {
            $operation();
        } catch (Throwable $e) {
            $this->suspendedUntil = $this->now() + $this->retryAfterSeconds;
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

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }

    /**
     * Lazily initializes the inner ElasticsearchHandler.
     *
     * Guards against circular initialization that can occur during
     * container compilation and cache warming.
     */
    private function getInnerHandler(): ?ElasticsearchHandler
    {
        if ($this->innerHandler !== null) {
            return $this->innerHandler;
        }

        if ($this->initializing) {
            return null;
        }

        $this->initializing = true;

        try {
            $this->innerHandler = new ElasticsearchHandler($this->client, $this->options);

            if ($this->kibanaFormatter !== null) {
                $this->innerHandler->setFormatter($this->kibanaFormatter);
            }
        } catch (Throwable $e) {
            $this->logger?->error('Failed to initialize ElasticsearchHandler', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $this->initializing = false;
        }

        return $this->innerHandler;
    }
}
