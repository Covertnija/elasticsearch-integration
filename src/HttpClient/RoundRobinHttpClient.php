<?php

declare(strict_types=1);

namespace EV\ElasticsearchIntegration\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP client implementing round-robin load balancing across multiple hosts.
 *
 * This client distributes HTTP requests across multiple Elasticsearch hosts
 * using a round-robin algorithm, providing load distribution and basic
 * fault tolerance.
 */
final class RoundRobinHttpClient implements ClientInterface
{
    /** @var array<string> Array of host URLs */
    private array $hosts;

    /** @var int Current host index for round-robin */
    private int $currentHostIndex = 0;

    /** @var array<string, Psr18Client> Array of HTTP clients for each host */
    private array $clients = [];

    private LoggerInterface $logger;

    /**
     * Initialize the round-robin HTTP client.
     *
     * @param array<string> $hosts Array of Elasticsearch host URLs
     * @param LoggerInterface|null $logger Optional logger for debugging
     *
     * @throws \InvalidArgumentException If no hosts are provided
     */
    public function __construct(array $hosts, LoggerInterface $logger = null)
    {
        if (empty($hosts)) {
            throw new \InvalidArgumentException('At least one host must be provided');
        }

        $this->hosts = $hosts;
        $this->logger = $logger ?? new NullLogger();
        $this->initializeClients();

        $this->logger->debug('RoundRobinHttpClient initialized', [
            'hosts_count' => count($hosts),
            'hosts' => $hosts,
        ]);
    }

    /**
     * Send an HTTP request using round-robin host selection.
     *
     * This method will try the current host first. If the request fails,
     * it will automatically retry with the next host in the rotation.
     *
     * @param RequestInterface $request The HTTP request to send
     *
     * @return ResponseInterface The HTTP response
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface If all hosts fail
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $exceptions = [];
        $attemptedHosts = [];

        // Try each host in the rotation until one succeeds
        for ($i = 0; $i < count($this->hosts); $i++) {
            $hostIndex = $this->getNextHostIndex();
            $host = $this->hosts[$hostIndex];
            $attemptedHosts[] = $host;

            try {
                $this->logger->debug('Attempting request to host', [
                    'host' => $host,
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                ]);

                $client = $this->clients[$host];
                $response = $client->sendRequest($request);

                $this->logger->debug('Request successful', [
                    'host' => $host,
                    'status_code' => $response->getStatusCode(),
                ]);

                return $response;
            } catch (\Exception $e) {
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
     * Get the next host index using round-robin algorithm.
     *
     * @return int The next host index
     */
    private function getNextHostIndex(): int
    {
        $index = $this->currentHostIndex;
        $this->currentHostIndex = ($this->currentHostIndex + 1) % count($this->hosts);

        return $index;
    }

    /**
     * Initialize HTTP clients for each host.
     */
    private function initializeClients(): void
    {
        foreach ($this->hosts as $host) {
            $this->clients[$host] = new Psr18Client();
        }
    }

    /**
     * Get the current host index.
     *
     * @return int Current host index
     */
    public function getCurrentHostIndex(): int
    {
        return $this->currentHostIndex;
    }

    /**
     * Get all configured hosts.
     *
     * @return array<string> Array of host URLs
     */
    public function getHosts(): array
    {
        return $this->hosts;
    }

    /**
     * Reset the round-robin counter to the first host.
     */
    public function reset(): void
    {
        $this->currentHostIndex = 0;
        $this->logger->debug('Round-robin counter reset');
    }
}
