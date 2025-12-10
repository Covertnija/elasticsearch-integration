<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;
use Http\Client\HttpAsyncClient;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating Elasticsearch clients with round-robin load balancing.
 *
 * This factory creates Elasticsearch clients that distribute requests across
 * multiple hosts using a round-robin algorithm for better load distribution
 * and fault tolerance.
 */
final class ElasticsearchRoundRobinClientFactory implements ElasticsearchClientFactoryInterface
{
    /**
     * Supported client options that can be passed to ClientBuilder.
     */
    private const SUPPORTED_OPTIONS = [
        'retries',
        'sslVerification',
        'elasticCloudId',
        'httpClient',
        'logger',
        'asyncHttpClient',
    ];

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
     * @param array<string, mixed> $options Additional client options
     *
     * @throws ElasticsearchConfigurationException If no hosts are provided
     * @throws AuthenticationException If authentication fails
     *
     * @return Client Configured Elasticsearch client
     */
    public function createClient(
        array $hosts,
        ?string $apiKey = null,
        array $options = [],
    ): Client {
        // Filter out empty host strings that might come from empty environment variables
        $hosts = array_filter($hosts, static fn (string $host): bool => $host !== '');

        if ($hosts === []) {
            throw ElasticsearchConfigurationException::emptyHosts();
        }

        $this->logger->debug('Creating Elasticsearch client with round-robin load balancing', [
            'hosts_count' => \count($hosts),
        ]);

        $clientBuilder = ClientBuilder::create()->setHosts($hosts);

        if ($apiKey !== null) {
            $clientBuilder->setApiKey($apiKey);
            $this->logger->debug('API key authentication configured for Elasticsearch client');
        }

        $this->applyClientOptions($clientBuilder, $options);

        return $clientBuilder->build();
    }

    /**
     * Apply client options to the ClientBuilder.
     *
     * @param ClientBuilder $clientBuilder The client builder instance
     * @param array<string, mixed> $options The options to apply
     *
     * @throws ElasticsearchConfigurationException If an unsupported option is provided
     */
    private function applyClientOptions(ClientBuilder $clientBuilder, array $options): void
    {
        foreach ($options as $option => $value) {
            if ($value === null) {
                continue;
            }

            match ($option) {
                'retries' => $clientBuilder->setRetries($this->validateInt($value, $option)),
                'sslVerification' => $clientBuilder->setSSLVerification($this->validateBool($value, $option)),
                'elasticCloudId' => $clientBuilder->setElasticCloudId($this->validateString($value, $option)),
                'httpClient' => $clientBuilder->setHttpClient($this->validateHttpClient($value, $option)),
                'logger' => $clientBuilder->setLogger($this->validateLogger($value, $option)),
                'asyncHttpClient' => $clientBuilder->setAsyncHttpClient($this->validateAsyncHttpClient($value, $option)),
                default => $this->logger->warning('Unsupported client option ignored', [
                    'option' => $option,
                    'supported_options' => self::SUPPORTED_OPTIONS,
                ]),
            };
        }
    }

    /**
     * Validate and cast value to int.
     *
     * @throws ElasticsearchConfigurationException If value is not numeric
     */
    private function validateInt(mixed $value, string $option): int
    {
        if (! is_numeric($value)) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected numeric value');
        }

        return (int) $value;
    }

    /**
     * Validate and cast value to bool.
     *
     * @throws ElasticsearchConfigurationException If value is not boolean
     */
    private function validateBool(mixed $value, string $option): bool
    {
        if (! \is_bool($value)) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected boolean value');
        }

        return $value;
    }

    /**
     * Validate and cast value to string.
     *
     * @throws ElasticsearchConfigurationException If value is not string
     */
    private function validateString(mixed $value, string $option): string
    {
        if (! \is_string($value)) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected string value');
        }

        return $value;
    }

    /**
     * Validate HTTP client.
     *
     * @throws ElasticsearchConfigurationException If value is not a ClientInterface
     */
    private function validateHttpClient(mixed $value, string $option): ClientInterface
    {
        if (! $value instanceof ClientInterface) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected Psr\\Http\\Client\\ClientInterface instance');
        }

        return $value;
    }

    /**
     * Validate logger.
     *
     * @throws ElasticsearchConfigurationException If value is not a LoggerInterface
     */
    private function validateLogger(mixed $value, string $option): LoggerInterface
    {
        if (! $value instanceof LoggerInterface) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected Psr\\Log\\LoggerInterface instance');
        }

        return $value;
    }

    /**
     * Validate async HTTP client.
     *
     * @throws ElasticsearchConfigurationException If value is not an HttpAsyncClient
     */
    private function validateAsyncHttpClient(mixed $value, string $option): HttpAsyncClient
    {
        if (! $value instanceof HttpAsyncClient) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected Http\\Client\\HttpAsyncClient instance');
        }

        return $value;
    }
}
