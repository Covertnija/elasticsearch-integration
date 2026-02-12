# Elasticsearch Integration

A Symfony bundle providing an Elasticsearch client with round-robin load balancing, automatic failover, and Kibana-compatible logging.

## Features

- **Round-robin load balancing** across multiple Elasticsearch nodes with automatic failover
- **Symfony Bundle integration** with full DI container support and autowiring
- **Flexible configuration** via YAML, environment variables, or programmatic setup
- **Kibana-compatible logging** via a Monolog formatter that maps `datetime` to `@timestamp`
- **Built-in Monolog handler** with lazy initialization and enable/disable support
- **Safe cache:clear** — lazy-loaded HTTP client prevents connections during container compilation
- **Host normalization** — handles nested arrays from `%env(csv:...)%` automatically
- **100% test coverage** with unit and integration tests
- **PHPStan level 9** strict static analysis
- **PSR-12 compliant** with strict typing (`declare(strict_types=1)`)

## Requirements

- PHP 8.2+
- Symfony 6.4+ or 7.x
- Elasticsearch 8.x+
- `elasticsearch/elasticsearch` ^9.2

## Installation

### Step 1: Install the package

```bash
composer require covertnija/elasticsearch-integration
```

### Step 2: Register the bundle

**With Symfony Flex** — done automatically. Flex will:
- Register the bundle in `config/bundles.php`
- Create `config/packages/elasticsearch_integration.yaml`
- Add environment variables to `.env`

**Without Symfony Flex** — add the bundle manually to `config/bundles.php`:

```php
return [
    // ...
    ElasticsearchIntegration\ElasticsearchIntegrationBundle::class => ['all' => true],
];
```

### Step 3: Configure environment variables

Add the following to your `.env` file (Flex does this automatically):

```env
ELASTICSEARCH_ENABLED=true
ELASTICSEARCH_HOSTS=http://localhost:9200
ELASTICSEARCH_API_KEY=
ELASTICSEARCH_INDEX=app-logs
```

For multiple hosts, use a comma-separated list:

```env
ELASTICSEARCH_HOSTS=http://es-node1:9200,http://es-node2:9200,http://es-node3:9200
```

### Step 4: Create the configuration file

If Flex didn't create it, add `config/packages/elasticsearch_integration.yaml`:

```yaml
elasticsearch_integration:
    enabled: '%env(bool:ELASTICSEARCH_ENABLED)%'
    hosts: '%env(csv:ELASTICSEARCH_HOSTS)%'
    api_key: '%env(default::ELASTICSEARCH_API_KEY)%'
    index: '%env(ELASTICSEARCH_INDEX)%'
    client_options: {}
```

## Configuration Reference

```yaml
elasticsearch_integration:
    # Enable or disable the integration (default: true)
    enabled: true

    # Elasticsearch host URLs — array or single string
    hosts:
        - 'http://localhost:9200'
        - 'http://localhost:9201'

    # API key for authentication (default: null)
    api_key: null

    # Default index name, used by the Kibana formatter (default: 'app-logs')
    index: 'app-logs'

    # Additional client options passed to Elasticsearch ClientBuilder
    client_options:
        retries: 3                # Number of retries on failure
        sslVerification: true     # Enable/disable SSL certificate verification
        # elasticCloudId: '...'   # Elastic Cloud deployment ID
```

## Usage

### Autowiring the Elasticsearch Client

The client is available via autowiring — just type-hint `Client`:

```php
use Elastic\Elasticsearch\Client;

class SearchService
{
    public function __construct(
        private Client $elasticsearch,
    ) {}

    public function search(string $index, array $query): array
    {
        return $this->elasticsearch->search([
            'index' => $index,
            'body'  => ['query' => $query],
        ])->asArray();
    }
}
```

### Using the Factory for Custom Clients

Inject the factory to create clients with different configurations:

```php
use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;

class CustomClientService
{
    public function __construct(
        private ElasticsearchClientFactoryInterface $factory,
    ) {}

    public function createClient(): Client
    {
        return $this->factory->createClient(
            hosts: ['http://custom-host:9200'],
            apiKey: 'custom-api-key',
            options: ['retries' => 5],
        );
    }
}
```

### Monolog / Kibana Integration

The bundle registers a `LazyElasticsearchHandler` that sends logs to Elasticsearch with Kibana-compatible `@timestamp` fields. The handler:

- **Defers initialization** — the inner `ElasticsearchHandler` is only created when the first log is written, avoiding connection issues during `cache:clear`
- **Respects the `enabled` flag** — silently discards logs when Elasticsearch is disabled
- **Auto-excludes the `elasticsearch` channel** — prevents circular logging where the handler's own ES requests generate logs that feed back into itself
- **Applies KibanaCompatibleFormatter** automatically

To use it, reference the bundle's handler service in your monolog config:

```yaml
# config/packages/prod/monolog.yaml
monolog:
    handlers:
        elasticsearch:
            type: service
            id: elasticsearch_integration.monolog_handler
            level: info
```

If you need a custom service name (e.g. for existing configs), create an alias:

```yaml
# config/services.yaml
services:
    app.monolog_handler.elasticsearch:
        alias: elasticsearch_integration.monolog_handler
```

The `KibanaCompatibleFormatter` renames Monolog's `datetime` field to `@timestamp`, which Kibana requires for time-based visualizations.

## Architecture

### Components

| Component | Description |
|-----------|-------------|
| `RoundRobinHttpClient` | PSR-18 HTTP client that distributes requests across hosts with automatic failover |
| `ElasticsearchRoundRobinClientFactory` | Factory that builds `Client` instances with validated options |
| `ElasticsearchConfig` | Immutable DTO for typed configuration with host normalization |
| `ElasticsearchExtension` | Symfony DI extension — registers all services programmatically |
| `KibanaCompatibleFormatter` | Monolog formatter mapping `datetime` → `@timestamp` |
| `LazyElasticsearchHandler` | Monolog handler with deferred initialization and enable/disable support |

### How Round-Robin Load Balancing Works

1. The `RoundRobinHttpClient` rotates through configured hosts on each request
2. If a host fails (`ClientExceptionInterface`), the next host is tried automatically
3. All hosts are attempted before throwing the first exception
4. The round-robin index persists across requests for even distribution
5. All operations are logged via the `elasticsearch` Monolog channel

### Available Services

| Service ID | Class | Description |
|------------|-------|-------------|
| `elasticsearch_integration.client` | `Elastic\Elasticsearch\Client` | Main ES client |
| `elasticsearch_integration.client_factory` | `ElasticsearchRoundRobinClientFactory` | Client factory |
| `elasticsearch_integration.round_robin_http_client` | `RoundRobinHttpClient` | Lazy HTTP client with round-robin failover |
| `elasticsearch_integration.kibana_formatter` | `KibanaCompatibleFormatter` | Monolog formatter |
| `elasticsearch_integration.monolog_handler` | `LazyElasticsearchHandler` | Monolog handler for ES logging |

All services are **private** and available via autowiring:

```php
use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
```

### Container Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `elasticsearch_integration.enabled` | `bool` | Whether the integration is active |
| `elasticsearch_integration.hosts` | `array<string>` | Configured host URLs |
| `elasticsearch_integration.index` | `string` | Default index name |
| `elasticsearch_integration.client_options` | `array` | Client builder options |
| `elasticsearch_integration.ssl_verification` | `bool` | Whether SSL certificate verification is enabled |

> **Security note**: The API key is **not** exposed as a container parameter. It is passed directly to the client factory at build time.

## Testing

```bash
# Run the test suite
composer test

# Run PHPStan (level 9)
composer phpstan

# Check code style (PSR-12)
composer cs-check

# Fix code style
composer cs-fix

# Run all checks at once
composer check
```

## Security

- **API key not leaked** — the API key is never stored as a container parameter
- **API key authentication** — use API keys instead of basic auth when possible
- **SSL/TLS** — always use HTTPS in production (`sslVerification: true`)
- **Self-signed certificates** — if your Elasticsearch cluster uses self-signed certificates, set `sslVerification: false` in `client_options`. This disables both peer and host verification for the HTTP transport. **Use only in trusted networks.**
- **Network security** — restrict access to Elasticsearch via firewall rules
- **Input validation** — sanitize all user input before sending to Elasticsearch

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes with tests (aim for 100% coverage)
4. Run `composer check` to verify tests, PHPStan, and code style
5. Submit a pull request

## License

MIT — see [LICENSE](LICENSE) for details.
