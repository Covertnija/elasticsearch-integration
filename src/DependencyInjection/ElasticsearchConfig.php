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
     */
    public function __construct(
        public bool $enabled,
        public array $hosts,
        public ?string $apiKey,
        public string $index,
        public array $clientOptions,
    ) {
    }

    /**
     * @param array{enabled: bool|string, hosts: array<mixed>, api_key: string|null, index: string, client_options: array<string, mixed>} $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) $config['enabled'],
            hosts: self::normalizeHosts($config['hosts']),
            apiKey: $config['api_key'],
            index: $config['index'],
            clientOptions: $config['client_options'],
        );
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
