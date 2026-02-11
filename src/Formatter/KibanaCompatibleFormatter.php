<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Formatter;

use Monolog\Formatter\ElasticsearchFormatter;

/**
 * Elasticsearch formatter that is compatible with Kibana.
 *
 * This formatter extends the standard ElasticsearchFormatter to rename
 * the 'datetime' field to '@timestamp', which is the expected field name
 * for time-based visualizations in Kibana.
 */
final class KibanaCompatibleFormatter extends ElasticsearchFormatter
{
    private const KIBANA_TIMESTAMP_FIELD = '@timestamp';

    private const MONOLOG_DATETIME_FIELD = 'datetime';

    public function __construct(string $index)
    {
        parent::__construct($index, '');
    }

    /**
     * @param array<mixed> $record
     *
     * @return array<string, mixed>
     */
    protected function getDocument(array $record): array
    {
        $document = parent::getDocument($record);

        if (isset($document[self::MONOLOG_DATETIME_FIELD])) {
            $document[self::KIBANA_TIMESTAMP_FIELD] = $document[self::MONOLOG_DATETIME_FIELD];
            unset($document[self::MONOLOG_DATETIME_FIELD]);
        }

        return $document;
    }
}
