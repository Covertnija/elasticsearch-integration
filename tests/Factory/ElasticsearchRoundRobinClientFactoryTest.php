<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Factory;

use Elastic\Elasticsearch\Exception\AuthenticationException;
use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for ElasticsearchRoundRobinClientFactory.
 */
final class ElasticsearchRoundRobinClientFactoryTest extends TestCase
{
    private ElasticsearchRoundRobinClientFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ElasticsearchRoundRobinClientFactory(new NullLogger());
    }

    /**
     * Test that creating a client with empty hosts throws an exception.
     *
     * @throws AuthenticationException
     */
    public function testCreateClientWithEmptyHostsThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('At least one Elasticsearch host must be provided');

        $this->factory->createClient([]);
    }

    /**
     * Test unsupported option logs warning.
     *
     * @throws AuthenticationException
     */
    public function testUnsupportedOptionLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                'Unsupported client option ignored',
                self::callback(static fn (array $context): bool => $context['option'] === 'unsupportedOption'),
            );

        $factory = new ElasticsearchRoundRobinClientFactory($logger);
        $hosts = ['http://localhost:9200'];
        $options = ['unsupportedOption' => 'value'];

        $factory->createClient($hosts, null, $options);
    }

    /**
     * Test creating a client with retries option.
     *
     * @throws AuthenticationException
     */
    public function testCreateClientWithRetriesOption(): void
    {
        $hosts = ['http://localhost:9200'];
        $options = ['retries' => 5];

        $this->factory->createClient($hosts, null, $options);

        // No exception means success - retries option was accepted
        $this->addToAssertionCount(1);
    }

    /**
     * Test creating a client with SSL verification option.
     *
     * @throws AuthenticationException
     */
    public function testCreateClientWithSslVerificationOption(): void
    {
        $hosts = ['http://localhost:9200'];
        $options = ['sslVerification' => false];

        $this->factory->createClient($hosts, null, $options);

        // No exception means success - sslVerification option was accepted
        $this->addToAssertionCount(1);
    }

    /**
     * Test invalid retries option throws exception.
     *
     * @throws AuthenticationException
     */
    public function testInvalidRetriesOptionThrowsException(): void
    {
        $hosts = ['http://localhost:9200'];
        $options = ['retries' => 'invalid'];

        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "retries": expected numeric value');

        $this->factory->createClient($hosts, null, $options);
    }

    /**
     * Test invalid SSL verification option throws exception.
     *
     * @throws AuthenticationException
     */
    public function testInvalidSslVerificationOptionThrowsException(): void
    {
        $hosts = ['http://localhost:9200'];
        $options = ['sslVerification' => 'invalid'];

        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "sslVerification": expected boolean value');

        $this->factory->createClient($hosts, null, $options);
    }

    /**
     * Test null option value is ignored.
     *
     * @throws AuthenticationException
     */
    public function testNullOptionValueIsIgnored(): void
    {
        $hosts = ['http://localhost:9200'];
        $options = ['retries' => null];

        $this->factory->createClient($hosts, null, $options);

        // No exception means success - null value was ignored
        $this->addToAssertionCount(1);
    }
}
