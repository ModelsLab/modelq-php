<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Unit;

use ModelsLab\ModelQ\ModelQ;
use PHPUnit\Framework\TestCase;
use Redis;

class QueueDepthTest extends TestCase
{
    private function makeModelQ(Redis $redis): ModelQ
    {
        return new ModelQ(redisClient: $redis, serverId: 'test-server');
    }

    public function testGetQueuedTaskCountUsesLlen(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('lLen')
            ->with('ml_tasks')
            ->willReturn(7);

        $modelq = $this->makeModelQ($redis);

        $this->assertSame(7, $modelq->getQueuedTaskCount());
    }

    public function testGetQueuedTaskCountReturnsZeroOnEmptyQueue(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('lLen')->with('ml_tasks')->willReturn(0);

        $this->assertSame(0, $this->makeModelQ($redis)->getQueuedTaskCount());
    }

    public function testGetQueuedTaskCountCastsFalseToZero(): void
    {
        // phpredis can return false on a connection hiccup; count must stay int.
        $redis = $this->createMock(Redis::class);
        $redis->method('lLen')->with('ml_tasks')->willReturn(false);

        $this->assertSame(0, $this->makeModelQ($redis)->getQueuedTaskCount());
    }

    public function testGetProcessingTaskCountUsesScard(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('sCard')
            ->with('processing_tasks')
            ->willReturn(3);

        $this->assertSame(3, $this->makeModelQ($redis)->getProcessingTaskCount());
    }
}
