<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Exception;

use RuntimeException;

/**
 * Exception thrown when Elasticsearch rejects one or more log records in a
 * bulk response (per-item errors like mapping errors).
 *
 * Rejected documents are a data problem, not an availability problem:
 * retrying the same documents would fail again, so this failure does not
 * suspend sending.
 */
final class RejectedRecordsException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $rejectedCount,
        public readonly int $status,
        public readonly string $errorType,
    ) {
        parent::__construct($message);
    }

    public static function fromBulkItems(int $rejectedCount, int $status, string $errorType, string $reason): self
    {
        return new self(
            sprintf(
                'Elasticsearch rejected %d log record(s); first error (status %d) %s: %s',
                $rejectedCount,
                $status,
                $errorType,
                $reason,
            ),
            $rejectedCount,
            $status,
            $errorType,
        );
    }
}
