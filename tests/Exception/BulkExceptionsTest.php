<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Exception;

use ElasticsearchIntegration\Exception\BulkBackPressureException;
use ElasticsearchIntegration\Exception\RejectedRecordsException;
use PHPUnit\Framework\TestCase;

final class BulkExceptionsTest extends TestCase
{
    public function testRejectedRecordsExceptionMessageAndProperties(): void
    {
        $e = RejectedRecordsException::fromBulkItems(2, 400, 'mapper_parsing_exception', 'failed to parse');

        self::assertSame(
            'Elasticsearch rejected 2 log record(s); first error (status 400) mapper_parsing_exception: failed to parse',
            $e->getMessage(),
        );
        self::assertSame(2, $e->rejectedCount);
        self::assertSame(400, $e->status);
        self::assertSame('mapper_parsing_exception', $e->errorType);
    }

    public function testRejectedRecordsExceptionWithoutItemDetails(): void
    {
        $e = RejectedRecordsException::fromBulkItems(0, 0, 'unknown', 'Elasticsearch reported errors without item details');

        self::assertSame(0, $e->rejectedCount);
        self::assertSame(0, $e->status);
        self::assertSame('unknown', $e->errorType);
    }

    public function testBulkBackPressureExceptionMessageAndProperties(): void
    {
        $e = BulkBackPressureException::fromBulkItem(429, 'es_rejected_execution_exception', 'rejected execution');

        self::assertSame(
            'Elasticsearch refused log records (status 429) es_rejected_execution_exception: rejected execution',
            $e->getMessage(),
        );
        self::assertSame(429, $e->status);
        self::assertSame('es_rejected_execution_exception', $e->errorType);
    }
}
