<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Util;

use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;
use ElasticsearchIntegration\Util\HostNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class HostNormalizerTest extends TestCase
{
    public function testNestedArraysAreFlattened(): void
    {
        $hosts = [['http://es1:9200', 'http://es2:9200'], ['http://es3:9200']];

        self::assertSame(
            ['http://es1:9200', 'http://es2:9200', 'http://es3:9200'],
            HostNormalizer::normalize($hosts),
        );
    }

    public function testMixedScalarAndNestedHostsAreFlattened(): void
    {
        $hosts = ['http://es1:9200', ['http://es2:9200', ['http://es3:9200']]];

        self::assertSame(
            ['http://es1:9200', 'http://es2:9200', 'http://es3:9200'],
            HostNormalizer::normalize($hosts),
        );
    }

    public function testHostsAreTrimmed(): void
    {
        self::assertSame(
            ['http://es1:9200', 'http://es2:9200'],
            HostNormalizer::normalize(['  http://es1:9200  ', "\thttp://es2:9200\n"]),
        );
    }

    public function testEmptyStringsAreSkipped(): void
    {
        self::assertSame(
            ['http://es1:9200'],
            HostNormalizer::normalize(['', '   ', 'http://es1:9200', '']),
        );
    }

    public function testNullEntriesAreSkipped(): void
    {
        self::assertSame(
            ['http://es1:9200'],
            HostNormalizer::normalize([null, 'http://es1:9200', [null]]),
        );
    }

    public function testEmptyInputReturnsEmptyList(): void
    {
        self::assertSame([], HostNormalizer::normalize([]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringLeafProvider(): iterable
    {
        yield 'int' => [9200];
        yield 'float' => [9.2];
        yield 'bool' => [true];
        yield 'object' => [new stdClass()];
    }

    #[DataProvider('nonStringLeafProvider')]
    public function testNonStringLeafThrows(mixed $host): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessageMatches('/Invalid Elasticsearch host: expected string, got/');

        HostNormalizer::normalize([$host]);
    }

    public function testNonStringLeafInNestedArrayThrows(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);

        HostNormalizer::normalize(['http://es1:9200', [9200]]);
    }
}
