<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Integration;

use ModelsLab\ModelQ\Middleware\Middleware;
use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Task\Task;
use PHPUnit\Framework\TestCase;
use Redis;

class EnqueueHookTracker extends Middleware
{
    public bool $before = false;

    public bool $after = false;

    public function beforeEnqueue(?Task $task): void
    {
        $this->before = true;
    }

    public function afterEnqueue(?Task $task): void
    {
        $this->after = true;
    }
}

/**
 * The pipelined enqueue, against a real phpredis.
 *
 * The unit tests pin the round-trip count with a mock, which cannot catch a
 * misuse of the actual pipeline API -- a chained call that phpredis rejects, or
 * an argument order that only differs on the wire. These run the real thing and
 * read the keys back.
 *
 * Uses a dedicated database index so it never touches whatever else is on this
 * Redis.
 */
class PipelinedEnqueueTest extends TestCase
{
    private const TEST_DB = 14;

    private Redis $redis;

    protected function setUp(): void
    {
        $this->redis = new Redis();

        try {
            $this->redis->connect('127.0.0.1', 6379, 1.0);
        } catch (\Throwable $e) {
            $this->markTestSkipped('No Redis on 127.0.0.1:6379');
        }

        $this->redis->select(self::TEST_DB);
        $this->redis->flushDb();
    }

    protected function tearDown(): void
    {
        $this->redis->flushDb();
        $this->redis->close();
    }

    private function modelq(): ModelQ
    {
        return (new ModelQ(redisClient: $this->redis, serverId: 'pipeline-test'))
            ->task('render', fn () => null);
    }

    public function testEveryKeyLandsInOneFlush(): void
    {
        $task = $this->modelq()->enqueue('render', ['prompt' => 'a cube'], 'pipe-1');

        $this->assertInstanceOf(Task::class, $task);
        $this->assertSame('pipe-1', $task->taskId);

        $this->assertSame(1, $this->redis->lLen('ml_tasks'), 'task not queued');
        $this->assertNotFalse($this->redis->get('task:pipe-1'), 'task state missing');
        $this->assertNotFalse($this->redis->get('task_history:pipe-1'), 'history entry missing');
        $this->assertSame(1, $this->redis->zCard('queued_requests'));
        $this->assertSame(1, $this->redis->zCard('task_history'));
    }

    public function testQueuedPayloadRoundTripsIntact(): void
    {
        $this->modelq()->enqueue('render', ['prompt' => 'a cube', 'samples' => 2], 'pipe-2');

        $queued = json_decode($this->redis->lIndex('ml_tasks', 0), true);

        $this->assertSame('pipe-2', $queued['task_id']);
        $this->assertSame('render', $queued['task_name']);
        $this->assertSame('queued', $queued['status']);
        $this->assertSame(
            ['prompt' => 'a cube', 'samples' => 2],
            $queued['payload']['data']['args'][0]
        );
    }

    public function testStoredStateMatchesTheQueuedCopy(): void
    {
        $this->modelq()->enqueue('render', ['prompt' => 'x'], 'pipe-3');

        $queued = json_decode($this->redis->lIndex('ml_tasks', 0), true);
        $stored = json_decode($this->redis->get('task:pipe-3'), true);

        $this->assertSame($queued['task_id'], $stored['task_id']);
        $this->assertSame($queued['queued_at'], $stored['queued_at']);
        $this->assertSame($queued['status'], $stored['status']);
    }

    /**
     * The history copy deliberately drops the payload -- an img2img request
     * carries a base64 image, and writing it a third time is pure bandwidth.
     */
    public function testHistoryCopyOmitsThePayload(): void
    {
        $this->modelq()->enqueue('render', ['init_image' => str_repeat('A', 4096)], 'pipe-4');

        $history = json_decode($this->redis->get('task_history:pipe-4'), true);

        $this->assertSame('pipe-4', $history['task_id']);
        $this->assertArrayNotHasKey('payload', $history);
        $this->assertLessThan(1024, strlen($this->redis->get('task_history:pipe-4')));
    }

    public function testSortedSetScoresAreTheRealTimestamps(): void
    {
        $before = microtime(true);
        $this->modelq()->enqueue('render', [], 'pipe-5');
        $after = microtime(true);

        $queuedScore = $this->redis->zScore('queued_requests', 'pipe-5');
        $historyScore = $this->redis->zScore('task_history', 'pipe-5');

        foreach (['queued_requests' => $queuedScore, 'task_history' => $historyScore] as $set => $score) {
            $this->assertIsFloat($score, "$set score missing");
            $this->assertGreaterThanOrEqual($before, $score, "$set score predates the call");
            $this->assertLessThanOrEqual($after, $score, "$set score postdates the call");
        }
    }

    public function testKeysCarryTheirExpiry(): void
    {
        $this->modelq()->enqueue('render', [], 'pipe-6');

        $this->assertGreaterThan(0, $this->redis->ttl('task:pipe-6'), 'task key never expires');
        $this->assertLessThanOrEqual(ModelQ::TASK_TTL, $this->redis->ttl('task:pipe-6'));
        $this->assertGreaterThan(0, $this->redis->ttl('task_history:pipe-6'));
    }

    public function testConsecutiveEnqueuesDoNotBleedIntoEachOther(): void
    {
        $modelq = $this->modelq();

        foreach (['a', 'b', 'c'] as $id) {
            $modelq->enqueue('render', ['prompt' => $id], "pipe-$id");
        }

        $this->assertSame(3, $this->redis->lLen('ml_tasks'));

        foreach (['a', 'b', 'c'] as $i => $id) {
            $queued = json_decode($this->redis->lIndex('ml_tasks', $i), true);
            $this->assertSame("pipe-$id", $queued['task_id'], 'queue order or identity drifted');
            $this->assertSame($id, $queued['payload']['data']['args'][0]['prompt']);

            $stored = json_decode($this->redis->get("task:pipe-$id"), true);
            $this->assertSame("pipe-$id", $stored['task_id']);
        }
    }

    /**
     * The connection must be usable immediately afterwards. A pipeline left
     * un-flushed would leave the socket in a state where the next plain command
     * reads a stale reply.
     */
    public function testConnectionIsCleanAfterEnqueue(): void
    {
        $modelq = $this->modelq();
        $modelq->enqueue('render', [], 'pipe-7');

        $this->assertTrue($this->redis->ping());
        $this->assertSame(1, $this->redis->lLen('ml_tasks'));

        $modelq->enqueue('render', [], 'pipe-8');
        $this->assertSame(2, $this->redis->lLen('ml_tasks'));
    }

    public function testMiddlewareStillFiresAroundThePipeline(): void
    {
        $tracker = new EnqueueHookTracker();

        $modelq = (new ModelQ(redisClient: $this->redis, serverId: 'pipeline-test'))
            ->task('render', fn () => null);
        $modelq->setMiddleware($tracker);

        $modelq->enqueue('render', [], 'pipe-9');

        $this->assertTrue($tracker->before, 'before_enqueue was skipped');
        $this->assertTrue($tracker->after, 'after_enqueue was skipped');
    }
}
