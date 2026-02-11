<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\HttpClient;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * HTTP client implementing round-robin load balancing across multiple hosts.
 *
 * This client distributes HTTP requests across multiple Elasticsearch hosts
 * using a round-robin algorithm, providing load distribution and basic
 * fault tolerance.
 */
class RoundRobinHttpClient implements ClientInterface
{
    /** @var array<string> */
    private array $hosts;

    private int $currentHostIndex = 0;

    private ClientInterface $httpClient;

    private LoggerInterface $logger;

    /**
     * @param array<string> $hosts
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        array $hosts,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($hosts === []) {
            throw new InvalidArgumentException('At least one host must be provided');
        }

        $this->hosts = $hosts;
        $this->httpClient = $httpClient ?? new Psr18Client();
        $this->logger = $logger ?? new NullLogger();

        $this->logger->debug('RoundRobinHttpClient initialized', [
            'hosts_count' => count($hosts),
            'hosts' => $hosts,
        ]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $exceptions = [];
        $attemptedHosts = [];

        // Try each host in the rotation until one succeeds
        for ($i = 0, $iMax = count($this->hosts); $i < $iMax; ++$i) {
            $hostIndex = $this->getNextHostIndex();
            $host = $this->hosts[$hostIndex];
            $attemptedHosts[] = $host;

            try {
                $hostRequest = $this->applyHost($request, $host);

                $this->logger->debug('Attempting request to host', [
                    'host' => $host,
                    'method' => $hostRequest->getMethod(),
                    'uri' => (string) $hostRequest->getUri(),
                ]);

                $response = $this->httpClient->sendRequest($hostRequest);

                $this->logger->debug('Request successful', [
                    'host' => $host,
                    'status_code' => $response->getStatusCode(),
                ]);

                return $response;
            } catch (ClientExceptionInterface $e) {
                $exceptions[] = $e;
                $this->logger->warning('Request to host failed, trying next host', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        // All hosts failed
        $this->logger->error('All hosts failed for request', [
            'attempted_hosts' => $attemptedHosts,
            'error_count' => count($exceptions),
        ]);

        throw $exceptions[0]; // Throw the first exception
    }

    /**
     * Applies the given host (scheme, hostname, port) to the request URI.
     */
    private function applyHost(RequestInterface $request, string $host): RequestInterface
    {
        $parsedHost = parse_url($host);
        $uri = $request->getUri();

        if (isset($parsedHost['scheme'])) {
            $uri = $uri->withScheme($parsedHost['scheme']);
        }

        $uri = $uri->withHost($parsedHost['host'] ?? $host);

        if (isset($parsedHost['port'])) {
            $uri = $uri->withPort($parsedHost['port']);
        }

        return $request->withUri($uri);
    }

    private function getNextHostIndex(): int
    {
        $index = $this->currentHostIndex;
        $this->currentHostIndex = ($this->currentHostIndex + 1) % count($this->hosts);

        return $index;
    }

    public function getCurrentHostIndex(): int
    {
        return $this->currentHostIndex;
    }

    /**
     * @return array<string>
     */
    public function getHosts(): array
    {
        return $this->hosts;
    }

    public function reset(): void
    {
        $this->currentHostIndex = 0;
        $this->logger->debug('Round-robin counter reset');
    }
}
