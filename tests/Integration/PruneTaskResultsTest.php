<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Integration;

use ModelsLab\ModelQ\ModelQ;
use PHPUnit\Framework\TestCase;
use Redis;
use ReflectionMethod;

/**
 * Counts value reads. phpredis `multi(Redis::PIPELINE)` returns the same object,
 * so this catches pipelined GETs as well as direct ones.
 */
class CountingRedis extends Redis
{
    public int $getCalls = 0;

    public function get($key): mixed
    {
        $this->getCalls++;

        return parent::get($key);
    }
}

class PruneTaskResultsTest extends TestCase
{
    private CountingRedis $redis;

    private ModelQ $modelq;

    protected function setUp(): void
    {
        $this->redis = new CountingRedis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->flushDb();
        $this->modelq = new ModelQ(redisClient: $this->redis);
        $this->redis->getCalls = 0;
    }

    protected function tearDown(): void
    {
        $this->redis->flushDb();
        $this->redis->close();
    }

    private function payload(float $finishedAt): string
    {
        return (string) json_encode(['status' => 'completed', 'finished_at' => $finishedAt]);
    }

    /**
     * The control case: a keyspace of healthy keys costs zero value reads.
     *
     * This is the whole point of the rewrite. The old implementation GET every
     * key on every pass; production showed 228.8M GETs to issue 2 deletes.
     */
    public function testHealthyKeysAreNeverRead(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->redis->setex("task_result:h{$i}", 3600, $this->payload(microtime(true)));
        }
        $this->redis->getCalls = 0;

        $pruned = $this->modelq->pruneOldTaskResults(86400);

        $this->assertSame(0, $pruned);
        $this->assertSame(0, $this->redis->getCalls, 'healthy keys must never have their value read');

        for ($i = 0; $i < 50; $i++) {
            $this->assertNotFalse($this->redis->get("task_result:h{$i}"));
            $this->assertGreaterThan(0, $this->redis->ttl("task_result:h{$i}"));
        }
    }

    /**
     * One broken key among many healthy ones costs exactly one read.
     */
    public function testOnlyTheKeyThatLostItsTtlIsRead(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->redis->setex("task_result:h{$i}", 3600, $this->payload(microtime(true)));
        }
        // No TTL, and old enough to prune.
        $this->redis->set('task_result:leaked', $this->payload(microtime(true) - 90000));
        $this->redis->set('task:leaked', $this->payload(microtime(true) - 90000));
        $this->redis->getCalls = 0;

        $pruned = $this->modelq->pruneOldTaskResults(86400);

        $this->assertSame(1, $pruned);
        $this->assertSame(1, $this->redis->getCalls, 'only the TTL-less key should be read');
        $this->assertFalse($this->redis->get('task_result:leaked'));
        $this->assertFalse($this->redis->get('task:leaked'), 'the task: twin must go too');
    }

    /**
     * A key with no TTL but not yet old is kept, and stops living forever.
     */
    public function testRecentOrphanIsBoundedNotDeleted(): void
    {
        $this->redis->set('task_result:fresh', $this->payload(microtime(true)));
        $this->assertSame(-1, $this->redis->ttl('task_result:fresh'));

        $pruned = $this->modelq->pruneOldTaskResults(86400);

        $this->assertSame(0, $pruned);
        $this->assertNotFalse($this->redis->get('task_result:fresh'));
        $this->assertGreaterThan(0, $this->redis->ttl('task_result:fresh'));
    }

    /**
     * The 60s worker loop must not call the scan; Redis TTLs handle expiry.
     */
    public function testWorkerLoopDoesNotScanTaskResults(): void
    {
        $method = new ReflectionMethod(ModelQ::class, 'startWorkers');
        $lines = (array) file((string) $method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        $body = (string) preg_replace('#^\s*//.*$#m', '', $body);

        $this->assertStringNotContainsString('pruneOldTaskResults', $body);
    }
}
