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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lazy-initializing Elasticsearch handler that defers ElasticsearchHandler construction.
 *
 * This handler solves the circular initialization problem during cache:clear where
 * the ElasticsearchHandler's ghost proxy triggers getDefaultFormatter() before
 * $this->options is populated. It also respects the enabled flag to silently
 * discard logs when Elasticsearch is disabled.
 */
class LazyElasticsearchHandler extends AbstractProcessingHandler
{
    private const EXCLUDED_CHANNEL = 'elasticsearch';

    private ?ElasticsearchHandler $innerHandler = null;

    private bool $initializing = false;

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

        $handler->handle($record);
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

        $handler->handleBatch($records);
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
