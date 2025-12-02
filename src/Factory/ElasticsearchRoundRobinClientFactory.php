<?php

declare(strict_types=1);

namespace EV\ElasticsearchIntegration\Factory;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating Elasticsearch clients with round-robin load balancing.
 *
 * This factory creates Elasticsearch clients that distribute requests across
 * multiple hosts using a round-robin algorithm for better load distribution
 * and fault tolerance.
 */
final class ElasticsearchRoundRobinClientFactory
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create an Elasticsearch client with round-robin load balancing.
     *
     * @param array<string> $hosts Array of Elasticsearch host URLs
     * @param string|null $apiKey Optional API key for authentication
     * @param array<string, mixed> $additionalOptions Additional client options
     *
     * @return Client Configured Elasticsearch client
     *
     * @throws \InvalidArgumentException If no hosts are provided
     */
    public function createClient(
        array $hosts,
        ?string $apiKey = null,
        array $additionalOptions = []
    ): Client {
        if (empty($hosts)) {
            throw new \InvalidArgumentException('At least one Elasticsearch host must be provided');
        }

        $this->logger->info('Creating Elasticsearch client with round-robin load balancing', [
            'hosts_count' => count($hosts),
            'hosts' => $hosts,
        ]);

        $clientBuilder = ClientBuilder::create()->setHosts($hosts);

        if ($apiKey !== null) {
            $clientBuilder->setApiKey($apiKey);
            $this->logger->debug('API key authentication configured for Elasticsearch client');
        }

        // Apply additional options
        foreach ($additionalOptions as $option => $value) {
            $clientBuilder->setConnectionParam($option, $value);
        }

        return $clientBuilder->build();
    }
}
