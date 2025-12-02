<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;

/**
 * Interface for Elasticsearch client factories.
 *
 * This interface defines the contract for creating Elasticsearch clients,
 * allowing for different implementations and easier testing.
 */
interface ElasticsearchClientFactoryInterface
{
    /**
     * Create an Elasticsearch client.
     *
     * @param array<string> $hosts Array of Elasticsearch host URLs
     * @param string|null $apiKey Optional API key for authentication
     * @param array<string, mixed> $options Additional client options
     *
     * @throws ElasticsearchConfigurationException If configuration is invalid
     * @throws AuthenticationException If authentication fails
     *
     * @return Client Configured Elasticsearch client
     */
    public function createClient(
        array $hosts,
        ?string $apiKey = null,
        array $options = [],
    ): Client;
}
