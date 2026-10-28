<?php

declare(strict_types=1);

namespace ElasticsearchIntegration\Tests\NodePool;

use DateTimeImmutable;
use Elastic\Transport\NodePool\Node;
use ElasticsearchIntegration\NodePool\TimedResurrect;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class TimedResurrectTest extends TestCase
{
    private DateTimeImmutable $now;

    public function testFirstPingReturnsFalse(): void
    {
        $resurrect = new TimedResurrect(60, $this->createClock());

        self::assertFalse($resurrect->ping($this->createNode()));
    }

    public function testPingBeforeCooldownReturnsFalse(): void
    {
        $resurrect = new TimedResurrect(60, $this->createClock());
        $node = $this->createNode();

        $resurrect->ping($node);
        $this->now = $this->now->modify('+59 seconds');

        self::assertFalse($resurrect->ping($node));
    }

    public function testPingAfterCooldownReturnsTrueAndClearsState(): void
    {
        $resurrect = new TimedResurrect(60, $this->createClock());
        $node = $this->createNode();

        $resurrect->ping($node);
        $this->now = $this->now->modify('+60 seconds');

        self::assertTrue($resurrect->ping($node));

        // The next ping starts a new cooldown window
        self::assertFalse($resurrect->ping($node));
    }

    public function testPingAtExactCooldownBoundaryReturnsTrue(): void
    {
        $resurrect = new TimedResurrect(60, $this->createClock());
        $node = $this->createNode();

        $resurrect->ping($node);
        $this->now = $this->now->modify('+60 seconds');

        self::assertTrue($resurrect->ping($node));
    }

    public function testZeroCooldownResurrectsImmediately(): void
    {
        $resurrect = new TimedResurrect(0, $this->createClock());

        self::assertTrue($resurrect->ping($this->createNode()));
        self::assertTrue($resurrect->ping($this->createNode()));
    }

    public function testNodesAreTrackedIndependently(): void
    {
        $resurrect = new TimedResurrect(60, $this->createClock());
        $nodeA = new Node('http://es1:9200');
        $nodeB = new Node('http://es2:9200');

        $resurrect->ping($nodeA);
        $this->now = $this->now->modify('+30 seconds');
        $resurrect->ping($nodeB);

        // B is still within its own window even though A's is older
        $this->now = $this->now->modify('+40 seconds');

        self::assertTrue($resurrect->ping($nodeA));
        self::assertFalse($resurrect->ping($nodeB));
    }

    private function createClock(): ClockInterface
    {
        $this->now = new DateTimeImmutable('2025-01-01 00:00:00');

        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(fn (): DateTimeImmutable => $this->now);

        return $clock;
    }

    private function createNode(): Node
    {
        return new Node('http://localhost:9200');
    }
}
