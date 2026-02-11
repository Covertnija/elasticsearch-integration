<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\HttpClient;

use ElasticsearchIntegration\HttpClient\RoundRobinHttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class RoundRobinHttpClientTest extends TestCase
{
    public function testInitializationWithValidHosts(): void
    {
        $hosts = ['http://localhost:9200', 'http://localhost:9201'];

        $client = new RoundRobinHttpClient($hosts, null, new NullLogger());

        self::assertSame($hosts, $client->getHosts());
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    public function testInitializationWithEmptyHostsThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one host must be provided');

        new RoundRobinHttpClient([], null, new NullLogger());
    }

    public function testReset(): void
    {
        $hosts = ['http://localhost:9200', 'http://localhost:9201'];

        $client = new RoundRobinHttpClient($hosts, null, new NullLogger());
        $client->reset();

        self::assertSame(0, $client->getCurrentHostIndex());
    }

    public function testClientWithoutLogger(): void
    {
        $hosts = ['http://localhost:9200'];

        $client = new RoundRobinHttpClient($hosts);

        self::assertSame($hosts, $client->getHosts());
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    public function testGetHostsReturnsCopy(): void
    {
        $hosts = ['http://localhost:9200', 'http://localhost:9201'];

        $client = new RoundRobinHttpClient($hosts, null, new NullLogger());
        $returnedHosts = $client->getHosts();

        // Modify returned array
        $returnedHosts[] = 'http://localhost:9202';

        // Original should be unchanged
        self::assertSame($hosts, $client->getHosts());
        self::assertNotSame($hosts, $returnedHosts);
    }

    public function testClientAcceptsInjectedHttpClient(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $hosts = ['http://localhost:9200'];

        $client = new RoundRobinHttpClient($hosts, $httpClient, new NullLogger());

        self::assertSame($hosts, $client->getHosts());
    }

    public function testSendRequestAppliesHostToUri(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('withScheme')->willReturnSelf();
        $uri->method('withHost')->willReturnSelf();
        $uri->method('withPort')->willReturnSelf();

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withUri')->willReturnSelf();
        $request->method('getMethod')->willReturn('GET');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn($response);

        $client = new RoundRobinHttpClient(
            ['http://localhost:9200'],
            $httpClient,
            new NullLogger(),
        );

        $result = $client->sendRequest($request);

        self::assertSame(200, $result->getStatusCode());
    }

    public function testSendRequestFailsOverToNextHost(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('withScheme')->willReturnSelf();
        $uri->method('withHost')->willReturnSelf();
        $uri->method('withPort')->willReturnSelf();

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withUri')->willReturnSelf();
        $request->method('getMethod')->willReturn('GET');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $exception = new class ('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($exception),
                $response,
            );

        $client = new RoundRobinHttpClient(
            ['http://localhost:9200', 'http://localhost:9201'],
            $httpClient,
            new NullLogger(),
        );

        $result = $client->sendRequest($request);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame(0, $client->getCurrentHostIndex());
    }

    public function testSendRequestThrowsWhenAllHostsFail(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('withScheme')->willReturnSelf();
        $uri->method('withHost')->willReturnSelf();
        $uri->method('withPort')->willReturnSelf();

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withUri')->willReturnSelf();
        $request->method('getMethod')->willReturn('GET');

        $exception = new class ('Connection refused') extends RuntimeException implements ClientExceptionInterface {};

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('sendRequest')
            ->willThrowException($exception);

        $client = new RoundRobinHttpClient(
            ['http://localhost:9200', 'http://localhost:9201'],
            $httpClient,
            new NullLogger(),
        );

        $this->expectException(ClientExceptionInterface::class);
        $this->expectExceptionMessage('Connection refused');

        $client->sendRequest($request);
    }

    public function testSendRequestAdvancesHostIndex(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('withScheme')->willReturnSelf();
        $uri->method('withHost')->willReturnSelf();
        $uri->method('withPort')->willReturnSelf();

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('withUri')->willReturnSelf();
        $request->method('getMethod')->willReturn('GET');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $client = new RoundRobinHttpClient(
            ['http://localhost:9200', 'http://localhost:9201', 'http://localhost:9202'],
            $httpClient,
            new NullLogger(),
        );

        $client->sendRequest($request);
        self::assertSame(1, $client->getCurrentHostIndex());

        $client->sendRequest($request);
        self::assertSame(2, $client->getCurrentHostIndex());

        $client->sendRequest($request);
        self::assertSame(0, $client->getCurrentHostIndex());
    }
}
