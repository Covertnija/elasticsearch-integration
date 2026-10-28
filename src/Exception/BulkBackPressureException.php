<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Exception;

use RuntimeException;

/**
 * Exception thrown when Elasticsearch refuses log records under back pressure
 * in a bulk response — per-item status 429, 5xx, or a cluster_block_exception.
 *
 * These are transient availability problems: the handler suspends sending
 * for the configured retry window, exactly like a transport failure.
 */
final class BulkBackPressureException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorType,
    ) {
        parent::__construct($message);
    }

    public static function fromBulkItem(int $status, string $errorType, string $reason): self
    {
        return new self(
            sprintf(
                'Elasticsearch refused log records (status %d) %s: %s',
                $status,
                $errorType,
                $reason,
            ),
            $status,
            $errorType,
        );
    }
}
