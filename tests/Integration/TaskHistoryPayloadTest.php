<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Integration;

use ModelsLab\ModelQ\ModelQ;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * History must not carry the request payload.
 *
 * Enqueueing legitimately stores the payload twice: in `ml_tasks` for the worker
 * to pop, and under `task:{id}` because requeueStuckProcessingTasks() rebuilds a
 * stuck job from that key. History was a third copy that nothing reads, so a
 * request carrying an inline base64 image pinned three times its own size in
 * Redis for a full retention window.
 */
class TaskHistoryPayloadTest extends TestCase
{
    private Redis $redis;

    private ModelQ $modelq;

    /** Stands in for an inline base64 image on a real generation request. */
    private const BIG_PAYLOAD_MARKER = 'PAYLOAD_MARKER_THAT_MUST_NOT_REACH_HISTORY';

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->flushDb();
        $this->modelq = new ModelQ(redisClient: $this->redis);
    }

    protected function tearDown(): void
    {
        $this->redis->flushDb();
        $this->redis->close();
    }

    private function enqueueWithBigPayload(string $taskId = 'task-history-payload-1'): array
    {
        $bigValue = self::BIG_PAYLOAD_MARKER.str_repeat('A', 200_000);

        $this->modelq->enqueue('txt2img', ['init_image' => $bigValue], $taskId);

        return [
            'history' => $this->redis->get("task_history:{$taskId}"),
            'task' => $this->redis->get("task:{$taskId}"),
            'queued' => $this->redis->lRange('ml_tasks', 0, -1)[0] ?? '',
        ];
    }

    public function testHistoryDoesNotStoreThePayload(): void
    {
        ['history' => $history] = $this->enqueueWithBigPayload();

        $this->assertNotFalse($history, 'history key should exist');
        $this->assertStringNotContainsString(self::BIG_PAYLOAD_MARKER, $history);
        $this->assertArrayNotHasKey('payload', json_decode($history, true));
    }

    public function testHistoryStillCarriesTheFieldsItsReadersUse(): void
    {
        ['history' => $history] = $this->enqueueWithBigPayload();
        $decoded = json_decode($history, true);

        // getTaskHistory() filters on these two and getTaskStats() counts by them.
        $this->assertSame('txt2img', $decoded['task_name']);
        $this->assertSame('queued', $decoded['status']);
        $this->assertSame('task-history-payload-1', $decoded['task_id']);
    }

    public function testQueueAndTaskKeyStillCarryThePayload(): void
    {
        ['task' => $task, 'queued' => $queued] = $this->enqueueWithBigPayload();

        // The worker pops this and needs the args.
        $this->assertStringContainsString(self::BIG_PAYLOAD_MARKER, $queued);

        // requeueStuckProcessingTasks() rebuilds a stuck job from task:{id}, so
        // stripping the payload here would silently requeue an argument-less job.
        $this->assertStringContainsString(self::BIG_PAYLOAD_MARKER, $task);
        $this->assertArrayHasKey('payload', json_decode($task, true));
    }

    public function testHistoryIsOrdersOfMagnitudeSmallerThanTheTaskKey(): void
    {
        ['history' => $history, 'task' => $task] = $this->enqueueWithBigPayload();

        $this->assertLessThan(
            strlen($task) / 10,
            strlen($history),
            'history should no longer scale with payload size'
        );
    }

    public function testGetTaskStatsStillReportsTheTask(): void
    {
        $this->enqueueWithBigPayload();

        $stats = $this->modelq->getTaskStats();

        $this->assertSame(1, $stats['by_status']['queued'] ?? 0);
        $this->assertSame(1, $stats['by_task_name']['txt2img']['total'] ?? 0);
    }
}
