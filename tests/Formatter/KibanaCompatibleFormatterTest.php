<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Formatter;

use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for KibanaCompatibleFormatter.
 */
final class KibanaCompatibleFormatterTest extends TestCase
{
    private const TEST_INDEX = 'test-logs';

    private KibanaCompatibleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new KibanaCompatibleFormatter(self::TEST_INDEX);
    }

    /**
     * Test that datetime field is renamed to @timestamp.
     */
    public function testDatetimeIsRenamedToTimestamp(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2024-01-15 10:30:00'),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
        );

        $formatted = $this->formatter->format($record);

        self::assertIsArray($formatted);
        self::assertArrayHasKey('@timestamp', $formatted);
        self::assertArrayNotHasKey('datetime', $formatted);
    }

    /**
     * Test that the formatted record contains expected fields.
     */
    public function testFormattedRecordContainsExpectedFields(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2024-01-15 10:30:00'),
            channel: 'app',
            level: Level::Warning,
            message: 'Warning message',
            context: ['user_id' => 123],
        );

        $formatted = $this->formatter->format($record);

        self::assertIsArray($formatted);
        self::assertSame('Warning message', $formatted['message']);
        self::assertSame('app', $formatted['channel']);
        self::assertSame('WARNING', $formatted['level_name']);
        self::assertSame(Level::Warning->value, $formatted['level']);
        self::assertArrayHasKey('context', $formatted);
        self::assertIsArray($formatted['context']);
        self::assertSame(123, $formatted['context']['user_id']);
    }

    /**
     * Test that the index is set correctly.
     */
    public function testIndexIsSetCorrectly(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test',
        );

        $formatted = $this->formatter->format($record);

        self::assertIsArray($formatted);
        self::assertArrayHasKey('_index', $formatted);
        self::assertSame(self::TEST_INDEX, $formatted['_index']);
    }

    /**
     * Test that timestamp format is ISO 8601 compatible.
     */
    public function testTimestampFormatIsIso8601(): void
    {
        $datetime = new \DateTimeImmutable('2024-01-15 10:30:00.123456');
        $record = new LogRecord(
            datetime: $datetime,
            channel: 'app',
            level: Level::Info,
            message: 'Test',
        );

        $formatted = $this->formatter->format($record);

        self::assertIsArray($formatted);
        self::assertArrayHasKey('@timestamp', $formatted);
        self::assertIsString($formatted['@timestamp']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $formatted['@timestamp'],
        );
    }
}
