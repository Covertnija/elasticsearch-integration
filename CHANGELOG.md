# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release of Elasticsearch Integration bundle
- **Symfony Flex recipe for automatic configuration** 🎉
- ElasticsearchRoundRobinClientFactory for creating Elasticsearch clients
- RoundRobinHttpClient for custom HTTP integrations with load balancing
- Symfony DI Extension with comprehensive configuration options
- Support for multiple Elasticsearch hosts with native load balancing
- API key authentication support
- Comprehensive logging integration with Monolog
- Full PHPUnit test coverage
- PSR-12 compliant code with strict typing
- PHP CS Fixer configuration for code style enforcement
- Automatic bundle registration via Flex
- Auto-generated configuration file
- Environment variables auto-added to .env

### Features
- **Multi-host support**: Configure multiple Elasticsearch hosts for load balancing
- **Native load balancing**: Leverages Elasticsearch PHP client v9.2+ built-in load balancing
- **Flexible configuration**: YAML-based configuration with sensible defaults
- **Logging**: Structured logging with configurable log levels
- **Type safety**: Full PHP 8.2+ strict typing support
- **Dependency injection**: Full Symfony DI container integration
- **Testing**: Comprehensive unit test suite

### Requirements
- PHP 8.2 or higher
- Symfony 6.4 or higher
- Elasticsearch 8.x or higher
- elasticsearch/elasticsearch ^9.2

## [1.0.0] - TBD

Initial stable release.
