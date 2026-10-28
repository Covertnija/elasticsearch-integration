<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Handler;

use Elastic\Elasticsearch\Response\Elasticsearch;
use ElasticsearchIntegration\Exception\BulkBackPressureException;
use ElasticsearchIntegration\Exception\RejectedRecordsException;
use Monolog\Handler\ElasticsearchHandler;
use Throwable;

/**
 * ElasticsearchHandler that classifies per-item bulk errors.
 *
 * The parent implementation only inspects `$item['index']['error']`, so with
 * op_type `create` every bulk rejection degenerates into a generic
 * InvalidArgumentException, and back-pressure signals (429, 5xx,
 * cluster_block_exception) cannot be distinguished from data problems like
 * mapping errors. This subclass returns RejectedRecordsException for data
 * problems and BulkBackPressureException for transient refusals.
 *
 * @internal
 */
final class BulkResponseAwareElasticsearchHandler extends ElasticsearchHandler
{
    /**
     * @param mixed[]|Elasticsearch $responses
     */
    protected function createExceptionFromResponses($responses): Throwable
    {
        $rejectedCount = 0;
        $firstStatus = 0;
        $firstType = 'unknown';
        $firstReason = '';

        $items = $responses['items'] ?? [];

        if (! is_iterable($items)) {
            $items = [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $result = reset($item);

            if (! is_array($result) || ! isset($result['error'])) {
                continue;
            }

            $rawStatus = $result['status'] ?? 0;
            $status = is_numeric($rawStatus) ? (int) $rawStatus : 0;
            $error = $result['error'];

            if (is_array($error)) {
                $type = isset($error['type']) && is_string($error['type']) ? $error['type'] : 'unknown';
                $reason = isset($error['reason']) && is_string($error['reason']) ? $error['reason'] : '';
            } else {
                $type = 'unknown';
                $reason = is_string($error) ? $error : '';
            }

            if ($status === 429 || $status >= 500 || $type === 'cluster_block_exception') {
                return BulkBackPressureException::fromBulkItem($status, $type, $reason);
            }

            ++$rejectedCount;

            if ($rejectedCount === 1) {
                $firstStatus = $status;
                $firstType = $type;
                $firstReason = $reason;
            }
        }

        if ($rejectedCount > 0) {
            return RejectedRecordsException::fromBulkItems($rejectedCount, $firstStatus, $firstType, $firstReason);
        }

        return RejectedRecordsException::fromBulkItems(
            0,
            0,
            'unknown',
            'Elasticsearch reported errors without item details',
        );
    }
}
