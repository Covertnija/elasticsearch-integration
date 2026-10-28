<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Transport\NodePool\Selector\RoundRobin;
use Elastic\Transport\NodePool\SimpleNodePool;
use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;
use ElasticsearchIntegration\NodePool\TimedResurrect;
use ElasticsearchIntegration\Util\HostNormalizer;
use Http\Client\HttpAsyncClient;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Psr18Client;

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
        'timeout',
        'resurrectAfterSeconds',
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
        $hosts = HostNormalizer::normalize($hosts);

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

        $retries = null;
        $timeout = null;
        $resurrectAfterSeconds = 60;
        $hasCustomHttpClient = isset($options['httpClient']);

        $this->applyClientOptions($clientBuilder, $options, $retries, $timeout, $resurrectAfterSeconds);

        $clientBuilder->setNodePool(new SimpleNodePool(
            new RoundRobin(),
            new TimedResurrect($resurrectAfterSeconds),
        ));

        if (! $hasCustomHttpClient) {
            $clientBuilder->setHttpClient(new Psr18Client());

            if ($timeout !== null) {
                $clientBuilder->setHttpClientOptions([
                    'timeout' => $timeout,
                    'max_duration' => $timeout,
                ]);
            }
        } elseif ($timeout !== null) {
            $this->logger->warning('timeout option ignored because a custom httpClient is configured');
        }

        $client = $clientBuilder->build();

        if ($retries !== null) {
            $client->getTransport()->setRetries($retries);
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ElasticsearchConfigurationException
     */
    private function applyClientOptions(
        ClientBuilder $clientBuilder,
        array $options,
        ?int &$retries,
        ?float &$timeout,
        int &$resurrectAfterSeconds,
    ): void {
        foreach ($options as $option => $value) {
            if ($value === null) {
                continue;
            }

            match ($option) {
                'retries' => $retries = $this->validateNonNegativeInt($value, $option),
                'sslVerification' => $clientBuilder->setSSLVerification($this->validateBool($value, $option)),
                'elasticCloudId' => $clientBuilder->setElasticCloudId($this->validateString($value, $option)),
                'httpClient' => $clientBuilder->setHttpClient($this->validateHttpClient($value, $option)),
                'logger' => $clientBuilder->setLogger($this->validateLogger($value, $option)),
                'asyncHttpClient' => $clientBuilder->setAsyncHttpClient($this->validateAsyncHttpClient($value, $option)),
                'timeout' => $timeout = $this->validateTimeout($value),
                'resurrectAfterSeconds' => $resurrectAfterSeconds = $this->validateNonNegativeInt($value, $option),
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
    private function validateNonNegativeInt(mixed $value, string $option): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value)) || (int) $value < 0) {
            throw ElasticsearchConfigurationException::invalidClientOption($option, 'expected integer value >= 0');
        }

        return (int) $value;
    }

    /**
     * @throws ElasticsearchConfigurationException
     */
    private function validateTimeout(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw ElasticsearchConfigurationException::invalidClientOption('timeout', 'expected numeric value');
        }

        $timeout = (float) $value;

        if ($timeout <= 0.0) {
            throw ElasticsearchConfigurationException::invalidClientOption('timeout', 'expected value > 0');
        }

        return $timeout;
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
}
