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
     * @param array<string> $hosts
     * @param array<string, mixed> $options
     *
     * @throws ElasticsearchConfigurationException
     * @throws AuthenticationException
     */
    public function createClient(
        array $hosts,
        ?string $apiKey = null,
        array $options = [],
    ): Client;
}
