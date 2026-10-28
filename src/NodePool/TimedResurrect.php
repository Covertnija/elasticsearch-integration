<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\NodePool;

use Elastic\Transport\NodePool\Node;
use Elastic\Transport\NodePool\Resurrect\ResurrectInterface;
use Psr\Clock\ClockInterface;

/**
 * Time-based node resurrection that never makes network calls.
 *
 * The transport's default NoResurrect keeps dead nodes dead for the lifetime
 * of the Client, so a temporary outage permanently breaks long-running
 * processes. ElasticsearchResurrect cannot be used instead: it pings
 * `HEAD /` through a separately discovered client that carries no API key,
 * timeout or SSL settings, so authenticated clusters never resurrect.
 *
 * This implementation simply puts dead nodes back into rotation after a
 * fixed cooldown, letting the next real request decide whether the node
 * recovered.
 */
final class TimedResurrect implements ResurrectInterface
{
    /**
     * @var array<string, int>
     */
    private array $deadSince = [];

    public function __construct(
        private readonly int $resurrectAfterSeconds = 60,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function ping(Node $node): bool
    {
        $key = (string) $node->getUri();
        $now = $this->now();

        if (! isset($this->deadSince[$key])) {
            if ($this->resurrectAfterSeconds === 0) {
                return true;
            }

            $this->deadSince[$key] = $now;

            return false;
        }

        if ($now - $this->deadSince[$key] >= $this->resurrectAfterSeconds) {
            unset($this->deadSince[$key]);

            return true;
        }

        return false;
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
