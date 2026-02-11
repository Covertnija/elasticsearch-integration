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
     * @param array<mixed> $hosts
     * @param array<string, mixed> $options
     *
     * @throws ElasticsearchConfigurationException
     * @throws AuthenticationException
     */
    public function createClient(
        array $hosts,
        ?string $apiKey = null,
        array $options = [],
    ): Client {
        $hosts = self::normalizeHosts($hosts);

        if ($hosts === []) {
            throw ElasticsearchConfigurationException::emptyHosts();
        }

        $this->logger->debug('Creating Elasticsearch client with round-robin load balancing', [
            'hosts_count' => count($hosts),
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
     * @param array<string, mixed> $options
     *
     * @throws ElasticsearchConfigurationException
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
     * @throws ElasticsearchConfigurationException
     */
    private function validateInt(mixed $value, string $option): int
    {
        if (! is_numeric($value)) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected numeric value');
        }

        return (int) $value;
    }

    /**
     * @throws ElasticsearchConfigurationException
     */
    private function validateBool(mixed $value, string $option): bool
    {
        if (! is_bool($value)) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected boolean value');
        }

        return $value;
    }

    /**
     * @throws ElasticsearchConfigurationException
     */
    private function validateString(mixed $value, string $option): string
    {
        if (! is_string($value)) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected string value');
        }

        return $value;
    }

    /**
     * @throws ElasticsearchConfigurationException
     */
    private function validateHttpClient(mixed $value, string $option): ClientInterface
    {
        if (! $value instanceof ClientInterface) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected Psr\\Http\\Client\\ClientInterface instance');
        }

        return $value;
    }

    /**
     * @throws ElasticsearchConfigurationException
     */
    private function validateLogger(mixed $value, string $option): LoggerInterface
    {
        if (! $value instanceof LoggerInterface) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected Psr\\Log\\LoggerInterface instance');
        }

        return $value;
    }

    /**
     * @throws ElasticsearchConfigurationException
     */
    private function validateAsyncHttpClient(mixed $value, string $option): HttpAsyncClient
    {
        if (! $value instanceof HttpAsyncClient) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected Http\\Client\\HttpAsyncClient instance');
        }

        return $value;
    }

    /**
     * Flattens nested host arrays that may result from env var processors like %env(csv:...)%.
     *
     * @param array<mixed> $hosts
     *
     * @return array<string>
     */
    private static function normalizeHosts(array $hosts): array
    {
        $normalized = [];

        array_walk_recursive($hosts, static function (string|int|float|bool $host) use (&$normalized): void {
            $normalized[] = (string) $host;
        });

        return array_values(array_filter($normalized, static fn (string $host): bool => $host !== ''));
    }
}
