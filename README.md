# Elasticsearch Integration

A Symfony bundle providing an Elasticsearch client with round-robin load balancing, automatic failover, and Kibana-compatible logging.

## Features

- **Round-robin load balancing** across multiple Elasticsearch nodes with automatic failover, provided by `elastic/transport`'s node pool
- **Symfony Bundle integration** with full DI container support and autowiring
- **Flexible configuration** via YAML, environment variables, or programmatic setup
- **Kibana-compatible logging** via a Monolog formatter that maps `datetime` to `@timestamp`
- **Built-in Monolog handler** with lazy client resolution, level filtering and enable/disable support
- **Safe cache:clear** — the client is only resolved when the first record is sent, so no connections happen during container compilation
- **Host normalization** — handles nested arrays from `%env(csv:...)%` automatically
- **PHPStan level 9** strict static analysis
- **PSR-12 compliant** with strict typing (`declare(strict_types=1)`)

## Upgrading to 2.0.0

Version 2.0.0 contains breaking changes:

- **`RoundRobinHttpClient` was removed.** Round-robin distribution, dead-node marking and failover are now handled by `elastic/transport`'s `SimpleNodePool` + `RoundRobin` selector built into the official client — the `httpClient` option is no longer wired by the bundle. If you extended or type-hinted `RoundRobinHttpClient`, remove that code.
- **`LazyElasticsearchHandler` accepts a `Closure` as its first argument** (`Client|Closure(): Client`). The compiled container now passes a service closure, so the client is not instantiated until the first record is sent. If you construct the handler manually with a `Client`, nothing changes.
- **`ElasticsearchConfig` was removed.** Use `ElasticsearchIntegration\Util\HostNormalizer::normalize()` for host normalization.
- **`elasticsearch_integration.api_key` and `elasticsearch_integration.ssl_verification` container parameters were removed.** The API key is passed directly to the client definition; `sslVerification` is now a normal `client_options` entry.
- **`retries` and other `client_options` are now actually applied.** `ClientBuilder::setRetries()` is a no-op in `elasticsearch/elasticsearch` 9.x, so the factory applies retries on the transport after `build()`. Previously the option was silently ignored.
- **New `timeout` client option** (`client_options.timeout`, default from the top-level `timeout` setting) controls the Symfony HTTP client idle/total timeout of the default PSR-18 client.
- **Dead nodes now resurrect after a cooldown.** The transport's default `NoResurrect` keeps failed nodes dead for the lifetime of the `Client` object — a temporary full outage permanently broke long-running processes. The factory now installs a `TimedResurrect` policy (`client_options.resurrectAfterSeconds`, default 60) that puts dead nodes back into rotation after the cooldown without pinging.
- **New `level` configuration option** sets the minimum log level of the bundled Monolog handler (MonologBundle ignores `level` on `type: service` handlers). It accepts plain strings — including `%env(...)%` placeholders.
- **`enabled` now accepts strings** — `'false'`, `'0'`, `'off'`, `'no'` and `''` all mean disabled (normalized via `filter_var(..., FILTER_VALIDATE_BOOL)`), so `%env(ELASTICSEARCH_ENABLED)%` without a `bool:` processor no longer enables the bundle by accident.
- **Bulk item errors are now classified properly.** Per-document data errors (other 4xx, e.g. mapping errors) produce a warning naming the rejected count and the first ES error, and do not suspend sending. Items with status 429, 5xx or `cluster_block_exception` suspend logging for `retry_after_seconds` like a transport failure. This also works for op_type `create`, where Monolog's own error parsing only produced a generic exception.
- **The suspension is shared across processes** via the new `cache_pool` option (a PSR-6 pool, default `cache.app`), so under PHP-FPM one failed send pauses logging in every worker.
- **Monolog `^3.3` is now required** (the `op_type` option was added in 3.3.0).

## Requirements

- PHP 8.4+
- Symfony 6.4, 7.x or 8.x
- Elasticsearch 9.x
- `elasticsearch/elasticsearch` ^9.2 — the 9.x PHP client sends
  `compatible-with=9` and is not compatible with Elasticsearch 8; per its own
  README, use the 8.x client if you need ES 8
- `monolog/monolog` ^3.3

## Installation

### Step 1: Install the package

```bash
composer require covertnija/elasticsearch-integration
```

### Step 2: Register the bundle

No Flex recipe is published for this bundle, so register it manually in `config/bundles.php`:

```php
return [
    // ...
    ElasticsearchIntegration\ElasticsearchIntegrationBundle::class => ['all' => true],
];
```

(`manifest.json` in the package root is a recipe template describing the intended bundle registration, config copy and env vars — apply the equivalent manually.)

### Step 3: Configure environment variables

Add the following to your `.env` file:

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

The standard Symfony skeleton loads `.env` via `symfony/dotenv`; without it,
define the variables in the real environment instead.

All four variables must be defined — even when the bundle is disabled — because
they are read when the handler service is constructed. Prefer `bool:` for
`ELASTICSEARCH_ENABLED` (`'%env(bool:ELASTICSEARCH_ENABLED)%'`); the handler
also normalizes plain strings like `'false'` or `'0'`, but `bool:` is clearer.

### Step 4: Create the configuration file

Add `config/packages/elasticsearch_integration.yaml`:

```yaml
elasticsearch_integration:
    enabled: '%env(bool:ELASTICSEARCH_ENABLED)%'
    hosts: '%env(csv:ELASTICSEARCH_HOSTS)%'
    api_key: '%env(default::ELASTICSEARCH_API_KEY)%'
    index: '%env(ELASTICSEARCH_INDEX)%'
    level: debug                       # minimum log level sent to Elasticsearch
    client_options: {}
    op_type: 'index'                    # use 'create' for data streams
    timeout: 5.0                        # HTTP timeout in seconds
    retry_after_seconds: 300            # pause sending after a failure
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

    # Minimum log level sent to Elasticsearch (default: 'debug')
    # One of: debug, info, notice, warning, error, critical, alert, emergency.
    # Env placeholders are allowed, e.g. level: '%env(LOG_LEVEL)%'
    level: debug

    # Additional client options passed to the client factory
    client_options:
        retries: 3                # Number of retries on failure
        sslVerification: true     # Enable/disable SSL certificate verification
        # elasticCloudId: '...'   # Elastic Cloud deployment ID
        # timeout: 2.5            # Override the top-level timeout for the client
        # resurrectAfterSeconds: 60  # Cooldown before a dead node re-enters rotation

    # Bulk action type: 'index' or 'create' (default: 'index')
    # Elasticsearch data streams: use 'create'; 'index' without an explicit _id is currently accepted on ES 9.5 but 'create' is documented
    op_type: 'index'

    # HTTP timeout in seconds for Elasticsearch requests — applies to both
    # the idle timeout and the total request duration (default: 5.0)
    # Merged into client_options as `timeout`; an explicit
    # client_options.timeout wins.
    timeout: 5.0

    # After a send failure, pause sending logs to Elasticsearch for this many
    # seconds; set to 0 to retry on every record (default: 300)
    retry_after_seconds: 300

    # PSR-6 cache pool service id used to share the send suspension across
    # processes — under PHP-FPM each worker rebuilds the handler per request,
    # so without a shared pool the pause would never carry over (default:
    # 'cache.app', set to null to disable)
    cache_pool: 'cache.app'
```

Supported `client_options` keys: `retries`, `sslVerification`, `elasticCloudId`, `httpClient`, `logger`, `asyncHttpClient`, `timeout`, `resurrectAfterSeconds`.

> **Warning — `logger` leaks credentials.** With the `logger` option set,
> `elastic/transport` logs every request line at info level and request
> headers and bodies at debug level — including the
> `Authorization: ApiKey …` header. Only use it with a logger that doesn't
> keep debug output, or one that redacts it.

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

The factory uses a deterministic `Symfony\Component\HttpClient\Psr18Client` by default. When you pass your own `httpClient` option, the `timeout` option is ignored (a warning is logged) — configure timeouts on your own client instead.

### Monolog / Kibana Integration

The bundle registers a `LazyElasticsearchHandler` that sends logs to Elasticsearch with Kibana-compatible `@timestamp` fields. The handler:

- **Defers client resolution** — the inner `ElasticsearchHandler` is only created when the first log is sent, avoiding connection issues during `cache:clear`
- **Respects the `enabled` flag** — silently discards logs when Elasticsearch is disabled
- **Applies the `level` option** — records below the configured level are dropped by the handler itself, since MonologBundle ignores `level` for `type: service` handlers
- **Auto-excludes the `elasticsearch` channel** — prevents circular logging where the handler's own ES requests generate logs that feed back into itself
- **Applies KibanaCompatibleFormatter** automatically; records are formatted exactly once, by the inner handler
- **Fail-safe** — Elasticsearch errors/timeouts never propagate; the failure is logged on the `elasticsearch` channel and sending is paused for `retry_after_seconds`, shared across processes via `cache_pool`
- **Classifies bulk item errors** — per-document data errors (other 4xx, e.g. mapping errors) produce a warning naming the count and the first ES error and do not pause sending; items with status 429, 5xx or `cluster_block_exception` suspend sending like a transport failure
- **Data stream support** — set `op_type: 'create'` when indexing into a data stream (`create` is the action Elasticsearch documents for data streams; `index` actions without an explicit `_id` — which is what Monolog sends — are currently accepted on Elasticsearch 9.5, but use `create` for data streams)

To use it, reference the bundle's handler service in your monolog config:

```yaml
# config/packages/prod/monolog.yaml
monolog:
    handlers:
        elasticsearch:
            type: service
            id: elasticsearch_integration.monolog_handler
```

Because the handler sends each record (or buffered batch) over HTTP synchronously, it is recommended to wrap it in a buffer handler so sending happens once per request, on flush:

```yaml
monolog:
    handlers:
        elasticsearch_buffer:
            type: buffer
            handler: elasticsearch
            level: info
            buffer_size: 500
            flush_on_overflow: true
        elasticsearch:
            type: service
            id: elasticsearch_integration.monolog_handler
            nested: true
```

Without a size limit, long-running workers can accumulate very large batches
before flushing. Note that the `level` you set on the wrapping handler gates
which records reach the buffer; the bundle's own `level` option filters again
at send time.

Worst-case blocking per send is approximately `(retries + 1) × timeout` before the handler suspends itself for `retry_after_seconds` — keep `timeout` and `retries` small if your application is latency-sensitive.

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
| `ElasticsearchRoundRobinClientFactory` | Factory that builds `Client` instances with validated options |
| `HostNormalizer` | Flattens/normalizes host lists (incl. nested arrays from `%env(csv:...)%`) |
| `ElasticsearchExtension` | Symfony DI extension — registers all services programmatically |
| `KibanaCompatibleFormatter` | Monolog formatter mapping `datetime` → `@timestamp` |
| `LazyElasticsearchHandler` | Monolog handler with deferred client resolution and enable/disable support |

### How Round-Robin Load Balancing Works

Load balancing and failover are provided by `elastic/transport`, the transport
layer of the official Elasticsearch client:

1. The configured hosts are registered in a `SimpleNodePool`
2. A `RoundRobin` selector rotates through the live nodes on each request
3. Nodes that fail with a network error are marked dead and put back into
   rotation after `resurrectAfterSeconds` (default 60) without a ping — the
   next real request decides whether the node recovered; if every node is
   dead, requests fail immediately with `NoNodeAvailableException` until the
   cooldown passes
4. `retries` controls how many times a request is re-attempted on other nodes
5. `timeout` bounds each individual request

Note: the bundle does **not** attach a logger to the transport — `elastic/transport`'s
request logging stays off unless you pass a PSR-3 logger via `client_options.logger`.

### Available Services

| Service ID | Class | Description |
|------------|-------|-------------|
| `elasticsearch_integration.client` | `Elastic\Elasticsearch\Client` | Main ES client |
| `elasticsearch_integration.client_factory` | `ElasticsearchRoundRobinClientFactory` | Client factory |
| `elasticsearch_integration.kibana_formatter` | `KibanaCompatibleFormatter` | Monolog formatter |
| `elasticsearch_integration.monolog_handler` | `LazyElasticsearchHandler` | Monolog handler for ES logging |

All services are **private** and available via autowiring:

```php
use Elastic\Elasticsearch\Client;
use ElasticsearchIntegration\Factory\ElasticsearchClientFactoryInterface;
use ElasticsearchIntegration\Formatter\KibanaCompatibleFormatter;
use ElasticsearchIntegration\Handler\LazyElasticsearchHandler;
```

### Container Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `elasticsearch_integration.enabled` | `bool\|string` | Whether the integration is active (strings are normalized) |
| `elasticsearch_integration.hosts` | `array<string>` | Configured host URLs |
| `elasticsearch_integration.index` | `string` | Default index name |
| `elasticsearch_integration.level` | `string` | Minimum log level sent to Elasticsearch |
| `elasticsearch_integration.client_options` | `array` | Client factory options |
| `elasticsearch_integration.op_type` | `string` | Bulk action type (`index` or `create`) |
| `elasticsearch_integration.timeout` | `float` | HTTP timeout in seconds for ES requests |
| `elasticsearch_integration.retry_after_seconds` | `int` | Pause in seconds after a send failure |

> **Security note**: The API key is **not** exposed as a container parameter. It is passed directly to the client definition, so it does not appear in `debug:container --parameters` output.

## Testing

```bash
# Run the test suite
composer test

# Run the live tests against a real Elasticsearch
# (requires ELASTICSEARCH_LIVE_TEST_HOSTS to be set)
ELASTICSEARCH_LIVE_TEST_HOSTS=http://localhost:9200 composer test
# (`localhost:9200` is from the host's point of view; inside the bundled
# docker/compose.yaml php container use http://elasticsearch:9200 — the
# compose file already sets ELASTICSEARCH_LIVE_TEST_HOSTS)

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

- **API key not leaked** — the API key is never stored as a container parameter; it is passed straight to the client definition
- **API key authentication** — use API keys instead of basic auth when possible
- **SSL/TLS** — always use HTTPS in production (`sslVerification: true`)
- **Self-signed certificates** — if your Elasticsearch cluster uses self-signed certificates, set `sslVerification: false` in `client_options`. This disables both peer and host verification for the HTTP transport. **Use only in trusted networks.**
- **Network security** — restrict access to Elasticsearch via firewall rules
- **Input validation** — sanitize all user input before sending to Elasticsearch

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes with tests
4. Run `composer check` to verify tests, PHPStan, and code style
5. Submit a pull request

## License

MIT — see [LICENSE](LICENSE) for details.
