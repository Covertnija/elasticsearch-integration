<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\Integration;

use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Integration tests for RoundRobinHttpClient request handling.
 *
 * These tests verify the full round-robin failover behavior
 * with mocked HTTP clients and requests.
 */
final class RoundRobinHttpClientIntegrationTest extends TestCase
{
    public function testSuccessfulRequestOnFirstHost(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn($response);

        $client = new RoundRobinHttpClient(
            ['http://host1:9200'],
            $httpClient,
        );

        $result = $client->sendRequest($this->createMockRequest());

        self::assertSame(200, $result->getStatusCode());
        // Single host: index 0 used, advances to 1, wraps to 0 (mod 1)
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    public function testFailoverToSecondHost(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $request = $this->createMockRequest();
        $exception = new TestNetworkException('Connection refused', $request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($exception),
                $response,
            );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Request to host failed, trying next host',
                $this->callback(static fn (array $context): bool => $context['host'] === 'http://host1:9200'
                    && $context['error'] === 'Connection refused'),
            );

        $client = new RoundRobinHttpClient(
            ['http://host1:9200', 'http://host2:9200'],
            $httpClient,
            $logger,
        );

        $result = $client->sendRequest($request);

        self::assertSame(200, $result->getStatusCode());
    }

    public function testAllHostsFailingThrowsFirstException(): void
    {
        $request = $this->createMockRequest();
        $exception1 = new TestNetworkException('Host 1 down', $request);
        $exception2 = new TestNetworkException('Host 2 down', $request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($exception1),
                $this->throwException($exception2),
            );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'All hosts failed for request',
                self::callback(static fn (array $context): bool => $context['error_count'] === 2
                    && count($context['attempted_hosts']) === 2),
            );

        $client = new RoundRobinHttpClient(
            ['http://host1:9200', 'http://host2:9200'],
            $httpClient,
            $logger,
        );

        $this->expectException(ClientExceptionInterface::class);
        $this->expectExceptionMessage('Host 1 down');

        $client->sendRequest($request);
    }

    public function testRoundRobinIndexAdvancesAcrossRequests(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $client = new RoundRobinHttpClient(
            ['http://host1:9200', 'http://host2:9200', 'http://host3:9200'],
            $httpClient,
        );

        // First request uses host1 (index 0), advances to 1
        $client->sendRequest($this->createMockRequest());
        self::assertSame(1, $client->getCurrentHostIndex());

        // Second request uses host2 (index 1), advances to 2
        $client->sendRequest($this->createMockRequest());
        self::assertSame(2, $client->getCurrentHostIndex());

        // Third request uses host3 (index 2), wraps to 0
        $client->sendRequest($this->createMockRequest());
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    public function testResetAfterRequests(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $client = new RoundRobinHttpClient(
            ['http://host1:9200', 'http://host2:9200'],
            $httpClient,
        );

        $client->sendRequest($this->createMockRequest());
        self::assertSame(1, $client->getCurrentHostIndex());

        $client->reset();
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    public function testSingleHostAllFailsThrowsException(): void
    {
        $request = $this->createMockRequest();
        $exception = new TestNetworkException('Connection refused', $request);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willThrowException($exception);

        $client = new RoundRobinHttpClient(
            ['http://host1:9200'],
            $httpClient,
        );

        $this->expectException(ClientExceptionInterface::class);

        $client->sendRequest($request);
    }

    private function createMockRequest(): RequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('http://localhost:9200/_search');
        $uri->method('withScheme')->willReturnSelf();
        $uri->method('withHost')->willReturnSelf();
        $uri->method('withPort')->willReturnSelf();

        $request = $this->createMock(RequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('withUri')->willReturnSelf();

        return $request;
    }
}

/**
 * PHPUnit cannot mock ClientExceptionInterface directly because it extends
 * Throwable which requires extending Exception.
 */
final class TestNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
