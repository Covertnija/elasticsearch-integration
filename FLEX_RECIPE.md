# Symfony Flex Recipe

This package includes a Symfony Flex recipe for automatic configuration.

## What Gets Configured Automatically

When you install this package with Symfony Flex, the following happens automatically:

### 1. Bundle Registration
The bundle is automatically registered in `config/bundles.php`:

```php
return [
    // ...
    EV\ElasticsearchIntegration\ElasticsearchIntegrationBundle::class => ['all' => true],
];
```

### 2. Configuration File
A configuration file is created at `config/packages/ev_elasticsearch_integration.yaml`:

```yaml
ev_elasticsearch_integration:
    enabled: '%env(bool:ELASTICSEARCH_ENABLED)%'
    hosts: '%env(csv:ELASTICSEARCH_HOSTS)%'
    api_key: '%env(default::ELASTICSEARCH_API_KEY)%'
    client_options: {}
    logging:
        enabled: true
        level: 'info'
```

### 3. Environment Variables
The following variables are added to your `.env` file:

```env
ELASTICSEARCH_ENABLED=true
ELASTICSEARCH_HOSTS=http://localhost:9200
ELASTICSEARCH_API_KEY=
```

### 4. Post-Install Message
After installation, you'll see helpful instructions in your terminal.

## Recipe Files

The Flex recipe consists of the following files:

- **`manifest.json`**: Defines what Flex should do during installation
- **`config/packages/ev_elasticsearch_integration.yaml`**: Default configuration template
- **`symfony.lock`**: Tracks the installed recipe version

## Manual Recipe Installation

If you need to manually apply the recipe (e.g., if Flex wasn't enabled during installation):

### Step 1: Register the Bundle

Edit `config/bundles.php`:

```php
return [
    // ... other bundles
    EV\ElasticsearchIntegration\ElasticsearchIntegrationBundle::class => ['all' => true],
];
```

### Step 2: Create Configuration File

Create `config/packages/ev_elasticsearch_integration.yaml`:

```yaml
ev_elasticsearch_integration:
    enabled: '%env(bool:ELASTICSEARCH_ENABLED)%'
    hosts: '%env(csv:ELASTICSEARCH_HOSTS)%'
    api_key: '%env(default::ELASTICSEARCH_API_KEY)%'
    client_options: {}
    logging:
        enabled: true
        level: 'info'
```

### Step 3: Add Environment Variables

Add to your `.env` file:

```env
###> ev/elasticsearch-integration ###
ELASTICSEARCH_ENABLED=true
ELASTICSEARCH_HOSTS=http://localhost:9200
ELASTICSEARCH_API_KEY=
###< ev/elasticsearch-integration ###
```

## Environment Variable Processors

The recipe uses Symfony's environment variable processors:

- **`%env(bool:ELASTICSEARCH_ENABLED)%`**: Converts string to boolean
- **`%env(csv:ELASTICSEARCH_HOSTS)%`**: Converts comma-separated string to array
- **`%env(default::ELASTICSEARCH_API_KEY)%`**: Uses empty string if not set

## Customization

After installation, you can customize the configuration:

### Multiple Hosts

```env
ELASTICSEARCH_HOSTS=http://es1:9200,http://es2:9200,http://es3:9200
```

### With API Key

```env
ELASTICSEARCH_API_KEY=your_api_key_here
```

### Custom Client Options

Edit `config/packages/ev_elasticsearch_integration.yaml`:

```yaml
ev_elasticsearch_integration:
    enabled: '%env(bool:ELASTICSEARCH_ENABLED)%'
    hosts: '%env(csv:ELASTICSEARCH_HOSTS)%'
    api_key: '%env(default::ELASTICSEARCH_API_KEY)%'
    client_options:
        timeout: 60
        connectTimeout: 10
        retryOnConflict: 3
    logging:
        enabled: true
        level: 'debug'
```

## Environment-Specific Configuration

You can override settings per environment:

### Development (`config/packages/dev/ev_elasticsearch_integration.yaml`)

```yaml
ev_elasticsearch_integration:
    logging:
        level: 'debug'
```

### Production (`config/packages/prod/ev_elasticsearch_integration.yaml`)

```yaml
ev_elasticsearch_integration:
    logging:
        level: 'warning'
    client_options:
        timeout: 30
```

### Test (`config/packages/test/ev_elasticsearch_integration.yaml`)

```yaml
ev_elasticsearch_integration:
    enabled: false  # Disable in tests
```

## Contributing a Recipe

If you want to contribute this recipe to the official Symfony Flex recipes repository:

1. Fork the [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib) repository
2. Create a directory: `ev/elasticsearch-integration/1.0/`
3. Add the recipe files:
   - `manifest.json`
   - `config/packages/ev_elasticsearch_integration.yaml`
4. Submit a pull request

## Troubleshooting

### Recipe Not Applied

If the recipe wasn't applied automatically:

1. Check if Symfony Flex is installed:
   ```bash
   composer show symfony/flex
   ```

2. If not installed, add it:
   ```bash
   composer require symfony/flex
   ```

3. Then reinstall the package:
   ```bash
   composer remove ev/elasticsearch-integration
   composer require ev/elasticsearch-integration
   ```

### Configuration Not Working

1. Clear the cache:
   ```bash
   php bin/console cache:clear
   ```

2. Check the configuration:
   ```bash
   php bin/console debug:config ev_elasticsearch_integration
   ```

3. Check the container:
   ```bash
   php bin/console debug:container elasticsearch
   ```

## Learn More

- [Symfony Flex Documentation](https://symfony.com/doc/current/setup/flex.html)
- [Creating Flex Recipes](https://github.com/symfony/recipes/blob/main/CONTRIBUTING.md)
- [Environment Variable Processors](https://symfony.com/doc/current/configuration/env_var_processors.html)
