<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Factory;

use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\NoNodeAvailableException;
use ElasticsearchIntegration\Exception\ElasticsearchConfigurationException;
use ElasticsearchIntegration\Factory\ElasticsearchRoundRobinClientFactory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpClient\Psr18Client;

final class ElasticsearchRoundRobinClientFactoryTest extends TestCase
{
    private ElasticsearchRoundRobinClientFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ElasticsearchRoundRobinClientFactory(new NullLogger());
    }

    /**
     * @throws AuthenticationException
     */
    public function testCreateClientWithEmptyHostsThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('At least one Elasticsearch host must be provided');

        $this->factory->createClient([]);
    }

    /**
     * @throws AuthenticationException
     */
    public function testUnsupportedOptionLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
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
     * @throws AuthenticationException
     */
    public function testCreateClientWithRetriesOption(): void
    {
        $client = $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['retries' => 3],
        );

        self::assertSame(3, $client->getTransport()->getRetries());
    }

    /**
     * @throws AuthenticationException
     */
    public function testDefaultRetriesEqualHostCount(): void
    {
        $client = $this->factory->createClient([
            'http://es1:9200',
            'http://es2:9200',
        ]);

        self::assertSame(2, $client->getTransport()->getRetries());
    }

    /**
     * @throws AuthenticationException
     */
    public function testNegativeRetriesOptionThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "retries"');

        $this->factory->createClient(['http://localhost:9200'], null, ['retries' => -1]);
    }

    /**
     * @throws AuthenticationException
     */
    public function testDefaultHttpClientIsPsr18Client(): void
    {
        $client = $this->factory->createClient(['http://localhost:9200']);

        self::assertInstanceOf(Psr18Client::class, $client->getTransport()->getClient());
    }

    /**
     * @throws AuthenticationException
     */
    public function testTimeoutOptionAcceptedWithDefaultHttpClient(): void
    {
        $client = $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['timeout' => 2.5],
        );

        self::assertInstanceOf(Psr18Client::class, $client->getTransport()->getClient());
    }

    /**
     * @throws AuthenticationException
     */
    public function testCustomHttpClientWithTimeoutKeepsClientAndLogsWarning(): void
    {
        $httpClient = self::createStub(ClientInterface::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('timeout option ignored because a custom httpClient is configured');

        $factory = new ElasticsearchRoundRobinClientFactory($logger);
        $client = $factory->createClient(
            ['http://localhost:9200'],
            null,
            [
                'httpClient' => $httpClient,
                'timeout' => 2.5,
            ],
        );

        self::assertSame($httpClient, $client->getTransport()->getClient());
    }

    /**
     * @throws AuthenticationException
     */
    public function testInvalidTimeoutOptionThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "timeout"');

        $this->factory->createClient(['http://localhost:9200'], null, ['timeout' => 'invalid']);
    }

    /**
     * @throws AuthenticationException
     */
    public function testZeroTimeoutOptionThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "timeout"');

        $this->factory->createClient(['http://localhost:9200'], null, ['timeout' => 0]);
    }

    /**
     * @throws AuthenticationException
     */
    public function testNullHostEntriesAreSkipped(): void
    {
        $client = $this->factory->createClient([null, 'http://localhost:9200', null]);

        self::assertSame(1, $client->getTransport()->getRetries());
    }

    /**
     * @throws AuthenticationException
     */
    public function testDeadNodeResurrectsImmediatelyWithZeroCooldown(): void
    {
        $exception = $this->createNetworkException();
        $calls = 0;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnCallback(function () use (&$calls, $exception): Response {
                ++$calls;

                if ($calls === 1) {
                    throw $exception;
                }

                return $this->createInfoResponse();
            });

        $client = $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            [
                'httpClient' => $httpClient,
                'retries' => 0,
                'resurrectAfterSeconds' => 0,
            ],
        );

        try {
            $client->info();
            self::fail('Expected the first request to fail');
        } catch (NoNodeAvailableException) {
            // The transport exhausted the retries and marked the node dead
        }

        $response = $client->info();

        self::assertInstanceOf(Elasticsearch::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $calls);
    }

    /**
     * @throws AuthenticationException
     */
    public function testDeadNodeStaysDeadDuringCooldown(): void
    {
        $exception = $this->createNetworkException();
        $calls = 0;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')
            ->willReturnCallback(function () use (&$calls, $exception): Response {
                ++$calls;

                if ($calls === 1) {
                    throw $exception;
                }

                return $this->createInfoResponse();
            });

        $client = $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            [
                'httpClient' => $httpClient,
                'retries' => 0,
                'resurrectAfterSeconds' => 60,
            ],
        );

        try {
            $client->info();
            self::fail('Expected the first request to fail');
        } catch (NoNodeAvailableException) {
            // The transport exhausted the retries and marked the node dead
        }

        try {
            $client->info();
            self::fail('Expected NoNodeAvailableException while the node is dead');
        } catch (NoNodeAvailableException) {
            // The node stays dead until the cooldown passes
        }

        self::assertSame(1, $calls);
    }

    /**
     * @throws AuthenticationException
     */
    public function testNegativeResurrectAfterSecondsThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "resurrectAfterSeconds"');

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['resurrectAfterSeconds' => -1],
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function testNonIntegerResurrectAfterSecondsThrowsException(): void
    {
        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "resurrectAfterSeconds"');

        $this->factory->createClient(
            ['http://localhost:9200'],
            null,
            ['resurrectAfterSeconds' => 'invalid'],
        );
    }

    private function createNetworkException(): NetworkExceptionInterface
    {
        return new class('Connection refused') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new Request('GET', 'http://localhost:9200');
            }
        };
    }

    private function createInfoResponse(): Response
    {
        return new Response(
            200,
            [
                'X-Elastic-Product' => 'Elasticsearch',
                'Content-Type' => 'application/json',
            ],
            '{"name":"node","cluster_name":"es","version":{"number":"9.5.4"},"tagline":"You Know, for Search"}',
        );
    }

    /**
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
     * @throws AuthenticationException
     */
    public function testInvalidRetriesOptionThrowsException(): void
    {
        $hosts = ['http://localhost:9200'];
        $options = ['retries' => 'invalid'];

        $this->expectException(ElasticsearchConfigurationException::class);
        $this->expectExceptionMessage('Invalid client option "retries": expected integer value >= 0');

        $this->factory->createClient($hosts, null, $options);
    }

    /**
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
