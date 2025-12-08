<?php

declare(strict_types=1);

namespace ElasticsearchIntegration;

use ElasticsearchIntegration\DependencyInjection\ElasticsearchExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle for Elasticsearch integration.
 *
 * This bundle provides Elasticsearch integration with round-robin load balancing,
 * comprehensive configuration options, and Monolog logging support.
 */
final class ElasticsearchIntegrationBundle extends Bundle
{
    /**
     * Get the bundle path.
     *
     * @return string The bundle root directory path
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new ElasticsearchExtension();
    }
}
