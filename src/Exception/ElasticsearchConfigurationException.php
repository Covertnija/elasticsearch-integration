<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Exception;

/**
 * Exception thrown when Elasticsearch configuration is invalid.
 *
 * This exception is used to indicate configuration errors such as
 * missing hosts, invalid options, or malformed configuration values.
 */
final class ElasticsearchConfigurationException extends \InvalidArgumentException
{
    /**
     * Create exception for empty hosts configuration.
     */
    public static function emptyHosts(): self
    {
        return new self('At least one Elasticsearch host must be provided');
    }

    /**
     * Create exception for invalid client option.
     *
     * @param string $option The invalid option name
     * @param string $reason The reason why it's invalid
     */
    public static function invalidClientOption(string $option, string $reason): self
    {
        return new self(\sprintf(
            'Invalid client option "%s": %s',
            $option,
            $reason,
        ));
    }
}
