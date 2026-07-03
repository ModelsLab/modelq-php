<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Integration;

use ModelsLab\ModelQ\ModelQ;
use PHPUnit\Framework\TestCase;
use Redis;

class QueueDepthIntegrationTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->flushDb();
    }

    protected function tearDown(): void
    {
        $this->redis->flushDb();
        $this->redis->close();
    }

    public function testQueuedTaskCountMatchesLlenAndOldCountPath(): void
    {
        $modelq = new ModelQ(redisClient: $this->redis, serverId: 'test');
        $modelq->task('depth_test', fn ($data) => $data);

        $this->assertSame(0, $modelq->getQueuedTaskCount());

        $modelq->enqueue('depth_test', ['n' => 1]);
        $modelq->enqueue('depth_test', ['n' => 2]);
        $modelq->enqueue('depth_test', ['n' => 3]);

        // New O(1) LLEN count.
        $this->assertSame(3, $modelq->getQueuedTaskCount());
        // Equals the raw Redis LLEN.
        $this->assertSame((int) $this->redis->lLen('ml_tasks'), $modelq->getQueuedTaskCount());
        // Equals the old, expensive LRANGE + deserialize path (all entries queued).
        $this->assertSame(count($modelq->getAllQueuedTasks()), $modelq->getQueuedTaskCount());
    }

    public function testProcessingTaskCountMatchesScard(): void
    {
        $modelq = new ModelQ(redisClient: $this->redis, serverId: 'test');

        $this->assertSame(0, $modelq->getProcessingTaskCount());

        $this->redis->sAdd('processing_tasks', 'task-a', 'task-b');

        $this->assertSame(2, $modelq->getProcessingTaskCount());
        $this->assertSame((int) $this->redis->sCard('processing_tasks'), $modelq->getProcessingTaskCount());
    }
}
