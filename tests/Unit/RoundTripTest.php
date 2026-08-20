<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Unit;

use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Task\Task;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Round trips are the cost, not commands.
 *
 * Producers run in us-east4; the ModelQ Redis is in Mumbai. Measured RTT is
 * ~271ms, so every reply this library waits for before sending the next
 * command is a quarter of a second on a caller's request path. Enqueue was
 * five such waits (1.36s, confirmed against 5,434 production requests) and the
 * result poll was two per 100ms tick.
 *
 * These tests pin the round-trip count, not the command list -- the commands
 * were never the problem.
 */
class RoundTripTest extends TestCase
{
    /**
     * A Redis double that records commands and counts pipeline flushes.
     *
     * @return array{0: Redis, 1: \stdClass}
     */
    private function recordingRedis(array|false $execReturn = []): array
    {
        $log = new \stdClass();
        $log->commands = [];   // every command, in the order issued
        $log->flushes = 0;     // exec() calls == round trips spent
        $log->pipelines = 0;   // multi(PIPELINE) calls

        $redis = $this->createMock(Redis::class);

        $redis->method('multi')->willReturnCallback(function ($mode = null) use ($redis, $log) {
            $log->pipelines++;
            $log->commands[] = ['multi', $mode];

            return $redis;
        });

        foreach (['setex', 'zAdd', 'rPush', 'get', 'set', 'lPush'] as $cmd) {
            $redis->method($cmd)->willReturnCallback(
                function (...$args) use ($redis, $log, $cmd) {
                    $log->commands[] = array_merge([$cmd], $args);

                    return $redis;
                }
            );
        }

        $redis->method('exec')->willReturnCallback(function () use ($log, $execReturn) {
            $log->flushes++;
            $log->commands[] = ['exec'];

            return $execReturn;
        });

        return [$redis, $log];
    }

    /** Command names issued between multi() and exec(), in order. */
    private function pipelinedCommands(\stdClass $log): array
    {
        $names = [];
        $inside = false;
        foreach ($log->commands as $entry) {
            if ($entry[0] === 'multi') {
                $inside = true;
                continue;
            }
            if ($entry[0] === 'exec') {
                $inside = false;
                continue;
            }
            if ($inside) {
                $names[] = $entry[0];
            }
        }

        return $names;
    }

    // -----------------------------------------------------------------
    // enqueue
    // -----------------------------------------------------------------

    public function testEnqueueCostsOneRoundTrip(): void
    {
        [$redis, $log] = $this->recordingRedis();

        (new ModelQ(redisClient: $redis, serverId: 'test'))
            ->task('render', fn () => null)
            ->enqueue('render', ['prompt' => 'a cube']);

        $this->assertSame(1, $log->pipelines, 'enqueue must open exactly one pipeline');
        $this->assertSame(1, $log->flushes, 'enqueue must wait for exactly one reply');
    }

    public function testEnqueueStillWritesAllFiveKeys(): void
    {
        [$redis, $log] = $this->recordingRedis();

        (new ModelQ(redisClient: $redis, serverId: 'test'))
            ->task('render', fn () => null)
            ->enqueue('render', [], 'task-42');

        $this->assertSame(
            ['setex', 'zAdd', 'setex', 'rPush', 'zAdd'],
            $this->pipelinedCommands($log),
            'pipelining must not drop a write'
        );

        $keys = [];
        foreach ($log->commands as $entry) {
            if (in_array($entry[0], ['setex', 'zAdd', 'rPush'], true)) {
                $keys[] = $entry[1];
            }
        }

        $this->assertSame(
            ['task:task-42', 'task_history', 'task_history:task-42', 'ml_tasks', 'queued_requests'],
            $keys
        );
    }

    /**
     * The task's own state must land before the id is advertised.
     *
     * `rPush ml_tasks` used to go first, so a worker could BLPOP a task whose
     * `task:{id}` key did not exist yet.
     */
    public function testTaskStateIsWrittenBeforeTheQueuePush(): void
    {
        [$redis, $log] = $this->recordingRedis();

        (new ModelQ(redisClient: $redis, serverId: 'test'))
            ->task('render', fn () => null)
            ->enqueue('render', [], 'task-7');

        $order = [];
        foreach ($log->commands as $entry) {
            if (($entry[1] ?? null) === 'task:task-7') {
                $order[] = 'state';
            }
            if (($entry[1] ?? null) === 'ml_tasks') {
                $order[] = 'queue';
            }
        }

        $this->assertSame(['state', 'queue'], $order);
    }

    public function testEnqueuedPayloadKeepsItsIdentity(): void
    {
        [$redis, $log] = $this->recordingRedis();

        (new ModelQ(redisClient: $redis, serverId: 'test'))
            ->task('render', fn () => null)
            ->enqueue('render', ['prompt' => 'a cube'], 'task-9');

        $pushed = null;
        foreach ($log->commands as $entry) {
            if ($entry[0] === 'rPush' && $entry[1] === 'ml_tasks') {
                $pushed = json_decode($entry[2], true);
            }
        }

        $this->assertIsArray($pushed);
        $this->assertSame('task-9', $pushed['task_id']);
        $this->assertSame('render', $pushed['task_name']);
        $this->assertSame('queued', $pushed['status']);
        $this->assertNotNull($pushed['queued_at']);
        $this->assertSame(['prompt' => 'a cube'], $pushed['payload']['data']['args'][0]);
    }

    // -----------------------------------------------------------------
    // getResult
    // -----------------------------------------------------------------

    public function testEachPollCostsOneRoundTripNotTwo(): void
    {
        // Two sequential GETs -- cancel flag, then result -- became one pipeline.
        [$redis, $log] = $this->recordingRedis([false, false]);

        $task = new Task('render', [], taskId: 'poll-1');

        try {
            $task->getResult($redis, timeout: 1);
        } catch (\ModelsLab\ModelQ\Exception\TaskTimeoutException) {
            // expected
        }

        $this->assertGreaterThan(0, $log->flushes);
        $this->assertSame(
            $log->pipelines,
            $log->flushes,
            'every poll must be one pipeline and one reply'
        );

        foreach (array_chunk($this->pipelinedCommands($log), 2) as $pair) {
            $this->assertSame(['get', 'get'], $pair);
        }
    }

    /**
     * The wait must back off, not hammer.
     *
     * At 100ms flat a 30s wait is ~300 wakes. Doubling to a 1s ceiling makes a
     * 3s wait cost 5 polls instead of 30, while still starting tight enough for
     * sub-second tasks.
     */
    public function testPollingBacksOff(): void
    {
        [$redis, $log] = $this->recordingRedis([false, false]);

        $task = new Task('render', [], taskId: 'poll-2');

        try {
            $task->getResult($redis, timeout: 3);
        } catch (\ModelsLab\ModelQ\Exception\TaskTimeoutException) {
            // expected
        }

        // 100 + 200 + 400 + 800 + 1000 + 1000ms => 6 polls in 3s.
        // A flat 100ms loop would be ~30. Generous ceiling to stay non-flaky.
        $this->assertLessThanOrEqual(10, $log->flushes, 'poll did not back off');
        $this->assertGreaterThanOrEqual(4, $log->flushes, 'poll backed off too aggressively');
    }

    public function testFirstPollIsStillFast(): void
    {
        // A task that is already done must not wait a full second for it.
        [$redis, $log] = $this->recordingRedis([
            false,
            json_encode(['status' => 'completed', 'result' => ['url' => 'out.png']]),
        ]);

        $task = new Task('render', [], taskId: 'poll-3');

        $started = microtime(true);
        $result = $task->getResult($redis, timeout: 5);
        $elapsed = microtime(true) - $started;

        $this->assertSame(['url' => 'out.png'], $result);
        $this->assertSame(1, $log->flushes, 'a ready result must be found on the first poll');
        $this->assertLessThan(0.1, $elapsed, 'a ready result must not sleep');
    }

    public function testCancelledFlagIsHonouredFromThePipeline(): void
    {
        [$redis] = $this->recordingRedis(['1', false]);

        $task = new Task('render', [], taskId: 'poll-4');

        $this->expectException(\ModelsLab\ModelQ\Exception\TaskProcessingException::class);
        $task->getResult($redis, timeout: 5);
    }

    /**
     * isCancelled() treats any stored value as cancelled, including "0".
     * The pipelined read must not quietly disagree with it.
     */
    public function testZeroStringStillCountsAsCancelled(): void
    {
        [$redis] = $this->recordingRedis(['0', false]);

        $task = new Task('render', [], taskId: 'poll-5');

        $this->expectException(\ModelsLab\ModelQ\Exception\TaskProcessingException::class);
        $task->getResult($redis, timeout: 5);
    }

    public function testFailedTaskStillThrows(): void
    {
        [$redis] = $this->recordingRedis([
            false,
            json_encode(['status' => 'failed', 'result' => 'CUDA out of memory']),
        ]);

        $task = new Task('render', [], taskId: 'poll-6');

        $this->expectException(\ModelsLab\ModelQ\Exception\TaskProcessingException::class);
        $this->expectExceptionMessage('CUDA out of memory');
        $task->getResult($redis, timeout: 5);
    }

    /**
     * A pipeline that does not come back cleanly says nothing about the task,
     * so the loop must keep waiting rather than read a broken reply as a verdict.
     */
    public function testUnusablePipelineReplyKeepsPolling(): void
    {
        [$redis, $log] = $this->recordingRedis(false);

        $task = new Task('render', [], taskId: 'poll-7');

        try {
            $task->getResult($redis, timeout: 1);
            $this->fail('expected a timeout, not a verdict');
        } catch (\ModelsLab\ModelQ\Exception\TaskTimeoutException) {
            $this->assertGreaterThan(1, $log->flushes, 'gave up after one bad reply');
        }
    }
}
