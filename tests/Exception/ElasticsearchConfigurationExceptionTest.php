<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Exception;

use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;
use PHPUnit\Framework\TestCase;

final class ElasticsearchConfigurationExceptionTest extends TestCase
{
    public function testEmptyHosts(): void
    {
        $exception = ElasticsearchConfigurationException::emptyHosts();

        self::assertSame('At least one Elasticsearch host must be provided', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
    }

    public function testInvalidClientOption(): void
    {
        $exception = ElasticsearchConfigurationException::invalidClientOption('badOption', 'not supported');

        self::assertSame('Invalid client option "badOption": not supported', $exception->getMessage());
    }
}
