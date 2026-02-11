<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Integration;

use ElasticsearchIntegration\DependencyInjection\ElasticsearchExtension;
use ElasticsearchIntegration\ElasticsearchIntegrationBundle;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ElasticsearchIntegrationBundle.
 *
 * These tests verify that the bundle correctly provides
 * its extension and path configuration.
 */
final class ElasticsearchIntegrationBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsElasticsearchExtension(): void
    {
        $bundle = new ElasticsearchIntegrationBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(ElasticsearchExtension::class, $extension);
    }

    public function testGetPathReturnsProjectRoot(): void
    {
        $bundle = new ElasticsearchIntegrationBundle();
        $path = $bundle->getPath();

        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/composer.json');
    }

    public function testExtensionAliasIsCorrect(): void
    {
        $bundle = new ElasticsearchIntegrationBundle();
        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('elasticsearch_integration', $extension->getAlias());
    }
}
