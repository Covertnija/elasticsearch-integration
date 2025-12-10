<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Formatter;

use Monolog\Formatter\ElasticsearchFormatter;
use Monolog\LogRecord;

/**
 * Elasticsearch formatter that is compatible with Kibana.
 *
 * This formatter extends the standard ElasticsearchFormatter to rename
 * the 'datetime' field to '@timestamp', which is the expected field name
 * for time-based visualizations in Kibana.
 */
final class KibanaCompatibleFormatter extends ElasticsearchFormatter
{
    /**
     * The field name used by Kibana for timestamp.
     */
    private const KIBANA_TIMESTAMP_FIELD = '@timestamp';

    /**
     * The field name used by Monolog for datetime.
     */
    private const MONOLOG_DATETIME_FIELD = 'datetime';

    /**
     * Create a new KibanaCompatibleFormatter instance.
     *
     * @param string $index Elasticsearch index name
     */
    public function __construct(string $index)
    {
        parent::__construct($index, '');
    }

    /**
     * Format a log record for Elasticsearch with Kibana-compatible timestamp.
     *
     * @param LogRecord $record The log record to format
     *
     * @return array<string, mixed> The formatted record
     */
    public function format(LogRecord $record): array
    {
        /** @var array<string, mixed> $formatted */
        $formatted = parent::format($record);

        if (isset($formatted[self::MONOLOG_DATETIME_FIELD])) {
            $formatted[self::KIBANA_TIMESTAMP_FIELD] = $formatted[self::MONOLOG_DATETIME_FIELD];
            unset($formatted[self::MONOLOG_DATETIME_FIELD]);
        }

        return $formatted;
    }
}
