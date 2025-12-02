# Elasticsearch Integration

A Symfony bundle providing an Elasticsearch client with multi-host support and load balancing.

## Features

- **Multi-host support** with automatic load balancing across Elasticsearch nodes
- **Symfony Bundle integration** with autoconfiguration
- **Flexible configuration** via Dependency Injection container
- **Production-ready** with comprehensive tests
- **Monolog integration** for logging Elasticsearch operations
- **PSR-12 compliant** code with strict typing

## Requirements

- PHP 8.2 or higher
- Symfony 6.4 or higher
- Elasticsearch 8.x or higher
- elasticsearch/elasticsearch ^9.2

## Installation

### With Symfony Flex (Recommended)

Install the bundle using Composer. Flex will automatically configure everything:

```bash
composer require covertnija/elasticsearch-integration
```

**That's it!** Symfony Flex will automatically:
- ✅ Register the bundle in `config/bundles.php`
- ✅ Create the configuration file `config/packages/elasticsearch_integration.yaml`
- ✅ Add environment variables to `.env`

### Without Symfony Flex (Manual Installation)

If you're not using Flex, install via Composer:

```bash
composer require covertnija/elasticsearch-integration
```

Then manually add the bundle to your `config/bundles.php`:

```php
return [
    // ...
    ElasticsearchIntegration\ElasticsearchIntegrationBundle::class => ['all' => true],
];
```

And create a configuration file `config/packages/elasticsearch_integration.yaml`:

## Configuration

### Basic Configuration (Auto-created by Flex)

```yaml
elasticsearch_integration:
    enabled: true
    hosts:
        - 'http://localhost:9200'
        - 'http://localhost:9201'
        - 'http://localhost:9202'
    api_key: '%env(ELASTICSEARCH_API_KEY)%'
    client_options:
        retries: 3
        sslVerification: true
```

### Environment Variables

With Symfony Flex, these are automatically added to your `.env` file. Otherwise, add them manually:

```env
# Elasticsearch Configuration
ELASTICSEARCH_ENABLED=true
ELASTICSEARCH_HOSTS=http://localhost:9200,http://localhost:9201
ELASTICSEARCH_API_KEY=

# Optional: For Monolog integration
ELASTICSEARCH_INDEX=app_logs
```

**Note**: `ELASTICSEARCH_HOSTS` can be a comma-separated list of hosts for load balancing.

## Usage

### Basic Usage

The Elasticsearch client is available as a service:

```php
use Elastic\Elasticsearch\Client;

class MyService
{
    public function __construct(private Client $elasticsearch)
    {
    }

    public function search(array $params): array
    {
        return $this->elasticsearch->search($params);
    }
}
```

### Using the Factory Directly

You can also use the factory directly for more control:

```php
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;

class MyService
{
    public function __construct(private ElasticsearchRoundRobinClientFactory $factory)
    {
    }

    public function createCustomClient(): Client
    {
        return $this->factory->createClient(
            ['http://custom-host:9200'],
            'custom-api-key'
        );
    }
}
```

### Monolog Integration

Configure Monolog to use Elasticsearch for logging:

```yaml
# config/packages/monolog.yaml
monolog:
    handlers:
        elasticsearch:
            type: service
            id: monolog.handler.elasticsearch
            level: info

services:
    monolog.handler.elasticsearch:
        class: Monolog\Handler\ElasticsearchHandler
        arguments:
            - '@elasticsearch.client'
            - { index: 'app_logs' }
```

## Architecture

### Core Components

- **ElasticsearchRoundRobinClientFactory**: Factory for creating Elasticsearch clients with multiple hosts
- **ElasticsearchExtension**: Symfony DI extension for configuration and service registration
- **Configuration**: Configuration schema definition

### Load Balancing

The Elasticsearch PHP client (v9.2+) natively handles load balancing when multiple hosts are provided:

1. Requests are automatically distributed across all configured hosts
2. Failed requests are retried with alternative hosts
3. Comprehensive logging tracks operations and failures
4. Built-in fault tolerance ensures service continuity

## Testing

Run the test suite:

```bash
composer test
```

Run static analysis with PHPStan (level 9):

```bash
composer phpstan
```

Run code style checks:

```bash
composer cs-check
```

Fix code style issues:

```bash
composer cs-fix
```

Run all quality checks at once:

```bash
composer check
```

## Configuration Reference

### Full Configuration Options

```yaml
elasticsearch_integration:
    # Enable/disable the integration
    enabled: true
    
    # Array of Elasticsearch host URLs
    hosts:
        - 'http://localhost:9200'
        - 'http://localhost:9201'
    
    # Optional API key for authentication
    api_key: null
    
    # Additional client options passed to Elasticsearch\ClientBuilder
    # Supported: retries, sslVerification, elasticCloudId
    client_options:
        retries: 3
        sslVerification: true
```

### Available Services

- `elasticsearch.client` - Main Elasticsearch client (public alias)
- `Elastic\Elasticsearch\Client` - Type-hinted autowiring
- `ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory` - Client factory
- `ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface` - Factory interface for testing

## Performance Considerations

- **Connection Pooling**: The HTTP client maintains connection pools for each host
- **Retry Logic**: Failed requests are automatically retried with the next available host
- **Logging**: Consider log levels in production to avoid performance impact
- **Timeout Configuration**: Adjust timeouts based on your network conditions and query complexity

## Security

- **API Key Authentication**: Use API keys instead of basic authentication when possible
- **SSL/TLS**: Always use HTTPS in production environments
- **Network Security**: Configure firewall rules to restrict access to Elasticsearch
- **Input Validation**: Always validate and sanitize user input before sending to Elasticsearch

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Run the test suite and code style checks
6. Submit a pull request

## License

This package is licensed under the MIT License. See the LICENSE file for details.

## Support

For issues and questions:
- Create an issue on GitHub
- Check the documentation
- Review the test cases for usage examples
