<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Util;

use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;

/**
 * Normalizes Elasticsearch host lists into a flat list of non-empty strings.
 *
 * Nested arrays may result from env var processors like %env(csv:...)%
 * when combined with other processors.
 */
final class HostNormalizer
{
    /**
     * @param array<mixed> $hosts
     *
     * @throws ElasticsearchConfigurationException
     *
     * @return list<string>
     */
    public static function normalize(array $hosts): array
    {
        $normalized = [];

        $flatten = static function (mixed $host) use (&$flatten, &$normalized): void {
            if (is_array($host)) {
                foreach ($host as $item) {
                    $flatten($item);
                }

                return;
            }

            if ($host === null) {
                return;
            }

            if (! is_string($host)) {
                throw ElasticsearchConfigurationException::invalidHost(get_debug_type($host));
            }

            $host = trim($host);

            if ($host !== '') {
                $normalized[] = $host;
            }
        };

        foreach ($hosts as $host) {
            $flatten($host);
        }

        return $normalized;
    }
}
