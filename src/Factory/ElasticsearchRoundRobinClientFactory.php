<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
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

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create an Elasticsearch client with round-robin load balancing.
     *
     * @param array<string> $hosts Array of Elasticsearch host URLs
     * @param string|null $apiKey Optional API key for authentication
     *
     * @throws \InvalidArgumentException If no hosts are provided
     * @throws AuthenticationException
     *
     * @return Client Configured Elasticsearch client
     */
    public function createClient(
        array $hosts,
        ?string $apiKey = null,
    ): Client {
        if ($hosts === []) {
            throw new \InvalidArgumentException('At least one Elasticsearch host must be provided');
        }

        $this->logger->info('Creating Elasticsearch client with round-robin load balancing', [
            'hosts_count' => \count($hosts),
            'hosts' => $hosts,
        ]);

        $clientBuilder = ClientBuilder::create()->setHosts($hosts);

        if ($apiKey !== null) {
            $clientBuilder->setApiKey($apiKey);
            $this->logger->debug('API key authentication configured for Elasticsearch client');
        }

        // Apply additional options (if supported by ClientBuilder)
        // Note: Elasticsearch v9 ClientBuilder may not support setConnectionParam
        // Additional options should be passed via setHosts or other specific methods

        return $clientBuilder->build();
    }
}
