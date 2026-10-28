<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\DependencyInjection;

/**
 * Typed configuration DTO for Elasticsearch integration.
 *
 * Replaces raw configuration arrays with a strongly-typed,
 * immutable value object for better type safety and readability.
 */
final readonly class ElasticsearchConfig
{
    /**
     * @param bool $enabled Whether the integration is enabled
     * @param array<string> $hosts Array of Elasticsearch host URLs
     * @param string|null $apiKey Optional API key for authentication
     * @param string $index Default Elasticsearch index name
     * @param array<string, mixed> $clientOptions Additional client options
     * @param string $opType Bulk action type; Elasticsearch data streams require "create"
     * @param float $timeout HTTP timeout in seconds for Elasticsearch requests
     * @param int $retryAfterSeconds Seconds to stop sending logs after a failure
     */
    public function __construct(
        public bool $enabled,
        public array $hosts,
        public ?string $apiKey,
        public string $index,
        public array $clientOptions,
        public string $opType = 'index',
        public float $timeout = 5.0,
        public int $retryAfterSeconds = 300,
    ) {
    }

    /**
     * @param array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>, op_type?: string, timeout?: float, retry_after_seconds?: int} $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) $config['enabled'],
            hosts: self::normalizeHosts($config['hosts']),
            apiKey: $config['api_key'],
            index: $config['index'],
            clientOptions: $config['client_options'],
            opType: $config['op_type'] ?? 'index',
            timeout: $config['timeout'] ?? 5.0,
            retryAfterSeconds: $config['retry_after_seconds'] ?? 300,
        );
    }

    /**
     * Flattens nested host arrays that may result from env var processors like %env(csv:...)%.
     *
     * @param array<mixed> $hosts
     *
     * @return array<string>
     */
    public static function normalizeHosts(array $hosts): array
    {
        $normalized = [];

        array_walk_recursive($hosts, static function (string|int|float|bool $host) use (&$normalized): void {
            $normalized[] = (string) $host;
        });

        return array_values(array_filter($normalized, static fn (string $host): bool => $host !== ''));
    }
}
