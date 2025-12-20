<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Integration;

use ModelsLab\ModelQ\Middleware\Middleware;
use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Task\Task;
use PHPUnit\Framework\TestCase;
use Redis;

class TrackerMiddleware extends Middleware
{
    public bool $beforeEnqueueCalled = false;
    public bool $afterEnqueueCalled = false;

    public function beforeEnqueue(?Task $task): void
    {
        $this->beforeEnqueueCalled = true;
    }

    public function afterEnqueue(?Task $task): void
    {
        $this->afterEnqueueCalled = true;
    }
}

class ModelQTest extends TestCase
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

    public function testInstantiation(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $this->assertInstanceOf(ModelQ::class, $modelq);
    }

    public function testInstantiationWithCustomRedisClient(): void
    {
        $customRedis = new Redis();
        $customRedis->connect('127.0.0.1', 6379);
        $customRedis->select(1);

        $modelq = new ModelQ(redisClient: $customRedis);

        $this->assertInstanceOf(ModelQ::class, $modelq);
        $this->assertSame($customRedis, $modelq->getRedisClient());
    }

    public function testTaskRegistration(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $modelq->task('my_task', fn($data) => $data);

        $this->assertContains('my_task', $modelq->getAllowedTasks());
    }

    public function testMultipleTaskRegistration(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $modelq->task('task_one', fn($data) => 1);
        $modelq->task('task_two', fn($data) => 2);
        $modelq->task('task_three', fn($data) => 3);

        $tasks = $modelq->getAllowedTasks();

        $this->assertContains('task_one', $tasks);
        $this->assertContains('task_two', $tasks);
        $this->assertContains('task_three', $tasks);
        $this->assertCount(3, $tasks);
    }

    public function testTaskRegistrationWithOptions(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $modelq->task('timeout_task', fn($data) => $data, ['timeout' => 60]);
        $modelq->task('retry_task', fn($data) => $data, ['retries' => 5]);
        $modelq->task('stream_task', fn($data) => $data, ['stream' => true]);

        $tasks = $modelq->getAllowedTasks();

        $this->assertContains('timeout_task', $tasks);
        $this->assertContains('retry_task', $tasks);
        $this->assertContains('stream_task', $tasks);
    }

    public function testServerRegistration(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $servers = $modelq->getRegisteredServerIds();

        $this->assertNotEmpty($servers);
        $this->assertIsArray($servers);
    }

    public function testEnqueue(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('enqueue_test', fn($data) => $data);

        $task = $modelq->enqueue('enqueue_test', ['key' => 'value']);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertNotEmpty($task->taskId);
        $this->assertEquals('queued', $task->status);
        $this->assertEquals('enqueue_test', $task->taskName);
        $this->assertEquals(['key' => 'value'], $task->payload['data']);
    }

    public function testGetAllQueuedTasks(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('queue_test', fn($data) => $data);

        $task1 = $modelq->enqueue('queue_test', ['num' => 1]);
        $task2 = $modelq->enqueue('queue_test', ['num' => 2]);

        $queued = $modelq->getAllQueuedTasks();

        $taskIds = array_column($queued, 'task_id');
        $this->assertContains($task1->taskId, $taskIds);
        $this->assertContains($task2->taskId, $taskIds);
    }

    public function testGetTaskStatus(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('status_test', fn($data) => $data);

        $task = $modelq->enqueue('status_test', []);
        $status = $modelq->getTaskStatus($task->taskId);

        $this->assertEquals('queued', $status);
    }

    public function testRemoveTaskFromQueue(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('remove_test', fn($data) => $data);

        $task = $modelq->enqueue('remove_test', []);
        $removed = $modelq->removeTaskFromQueue($task->taskId);

        $this->assertTrue($removed);

        $queued = $modelq->getAllQueuedTasks();
        $taskIds = array_column($queued, 'task_id');
        $this->assertNotContains($task->taskId, $taskIds);
    }

    public function testDeleteQueue(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('clear_test', fn($data) => $data);

        $modelq->enqueue('clear_test', ['a' => 1]);
        $modelq->enqueue('clear_test', ['b' => 2]);
        $modelq->enqueue('clear_test', ['c' => 3]);

        $modelq->deleteQueue();

        $queued = $modelq->getAllQueuedTasks();
        $this->assertEmpty($queued);
    }

    public function testEnqueueDelayedTask(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $taskDict = [
            'task_id' => 'delayed-task-123',
            'task_name' => 'delayed_test',
            'payload' => ['delayed' => true],
            'status' => 'queued',
        ];

        $modelq->enqueueDelayedTask($taskDict, 60);

        $redis = $modelq->getRedisClient();
        $delayed = $redis->zRange('delayed_tasks', 0, -1);

        $found = false;
        foreach ($delayed as $item) {
            $data = json_decode($item, true);
            if ($data['task_id'] === 'delayed-task-123') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    public function testGetProcessingTasks(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $redis->sAdd('processing_tasks', 'proc-task-123');
        $redis->set('task:proc-task-123', json_encode([
            'task_id' => 'proc-task-123',
            'task_name' => 'test',
            'status' => 'processing',
            'payload' => [],
        ]));

        $processing = $modelq->getProcessingTasks();

        $this->assertCount(1, $processing);
        $this->assertEquals('proc-task-123', $processing[0]['task_id']);

        // Cleanup
        $redis->sRem('processing_tasks', 'proc-task-123');
        $redis->del('task:proc-task-123');
    }

    public function testMiddlewareHooks(): void
    {
        $middleware = new TrackerMiddleware();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->setMiddleware($middleware);
        $modelq->task('middleware_test', fn($data) => $data);

        $this->assertFalse($middleware->beforeEnqueueCalled);
        $this->assertFalse($middleware->afterEnqueueCalled);

        $modelq->enqueue('middleware_test', []);

        $this->assertTrue($middleware->beforeEnqueueCalled);
        $this->assertTrue($middleware->afterEnqueueCalled);
    }

    public function testGetRedisClient(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $this->assertInstanceOf(Redis::class, $redis);

        $redis->set('modelq_test_key', 'test_value');
        $value = $redis->get('modelq_test_key');

        $this->assertEquals('test_value', $value);
    }

    // -------------------------------------------------------------------------
    // Task Cancellation Tests
    // -------------------------------------------------------------------------

    public function testCancelTask(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('cancel_test', fn($data) => $data);

        $task = $modelq->enqueue('cancel_test', ['key' => 'value']);

        $result = $modelq->cancelTask($task->taskId);

        $this->assertTrue($result);
        $this->assertTrue($modelq->isTaskCancelled($task->taskId));
    }

    public function testCancelNonexistentTask(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $result = $modelq->cancelTask('nonexistent-task-id');

        $this->assertFalse($result);
    }

    public function testIsTaskCancelled(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        // Not cancelled
        $this->assertFalse($modelq->isTaskCancelled('uncancelled-task'));

        // Set cancellation flag
        $redis->set('task:cancelled-task:cancelled', '1');
        $this->assertTrue($modelq->isTaskCancelled('cancelled-task'));
    }

    public function testGetCancelledTasks(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'cancelled_1', 'task_name' => 'test', 'status' => 'cancelled', 'created_at' => $now - 100],
            ['task_id' => 'cancelled_2', 'task_name' => 'test', 'status' => 'completed', 'created_at' => $now - 50],
            ['task_id' => 'cancelled_3', 'task_name' => 'test', 'status' => 'cancelled', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $cancelled = $modelq->getCancelledTasks();

        $this->assertCount(2, $cancelled);
        foreach ($cancelled as $t) {
            $this->assertEquals('cancelled', $t['status']);
        }
    }

    // -------------------------------------------------------------------------
    // Progress Tracking Tests
    // -------------------------------------------------------------------------

    public function testReportProgress(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $taskId = 'progress-test-1';

        $modelq->reportProgress($taskId, 0.5, 'Halfway done');

        $progress = $modelq->getTaskProgress($taskId);

        $this->assertNotNull($progress);
        $this->assertEquals(0.5, $progress['progress']);
        $this->assertEquals('Halfway done', $progress['message']);
        $this->assertArrayHasKey('updated_at', $progress);
    }

    public function testReportProgressClampsValues(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $taskId = 'progress-clamp-test';

        // Test clamping above 1
        $modelq->reportProgress($taskId, 1.5, 'Over 100%');
        $progress = $modelq->getTaskProgress($taskId);
        $this->assertEquals(1.0, $progress['progress']);

        // Test clamping below 0
        $modelq->reportProgress($taskId, -0.5, 'Negative');
        $progress = $modelq->getTaskProgress($taskId);
        $this->assertEquals(0.0, $progress['progress']);
    }

    public function testGetTaskProgressNotFound(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $progress = $modelq->getTaskProgress('nonexistent-task');

        $this->assertNull($progress);
    }

    public function testReportProgressWithoutMessage(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $taskId = 'progress-no-msg-test';

        $modelq->reportProgress($taskId, 0.25);

        $progress = $modelq->getTaskProgress($taskId);
        $this->assertEquals(0.25, $progress['progress']);
        $this->assertNull($progress['message']);
    }

    // -------------------------------------------------------------------------
    // Task History Tests
    // -------------------------------------------------------------------------

    public function testGetTaskDetails(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $taskData = [
            'task_id' => 'detail-task-1',
            'task_name' => 'process_image',
            'status' => 'queued',
            'created_at' => time(),
        ];

        $redis->set("task_history:{$taskData['task_id']}", json_encode($taskData));
        $redis->zAdd('task_history', $taskData['created_at'], $taskData['task_id']);

        $details = $modelq->getTaskDetails('detail-task-1');

        $this->assertNotNull($details);
        $this->assertEquals('detail-task-1', $details['task_id']);
        $this->assertEquals('process_image', $details['task_name']);
        $this->assertEquals('queued', $details['status']);
    }

    public function testGetTaskDetailsNotFound(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $details = $modelq->getTaskDetails('nonexistent-task');

        $this->assertNull($details);
    }

    public function testGetTaskHistory(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'hist_1', 'task_name' => 'task_a', 'status' => 'completed', 'created_at' => $now - 100],
            ['task_id' => 'hist_2', 'task_name' => 'task_b', 'status' => 'failed', 'created_at' => $now - 50],
            ['task_id' => 'hist_3', 'task_name' => 'task_a', 'status' => 'queued', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $history = $modelq->getTaskHistory(10);

        $this->assertCount(3, $history);
        // Most recent first
        $this->assertEquals('hist_3', $history[0]['task_id']);
        $this->assertEquals('hist_2', $history[1]['task_id']);
        $this->assertEquals('hist_1', $history[2]['task_id']);
    }

    public function testGetTaskHistoryWithStatusFilter(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'filter_1', 'task_name' => 'task_a', 'status' => 'completed', 'created_at' => $now - 100],
            ['task_id' => 'filter_2', 'task_name' => 'task_b', 'status' => 'failed', 'created_at' => $now - 50],
            ['task_id' => 'filter_3', 'task_name' => 'task_a', 'status' => 'completed', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $completed = $modelq->getTaskHistory(100, 0, 'completed');
        $this->assertCount(2, $completed);
        foreach ($completed as $t) {
            $this->assertEquals('completed', $t['status']);
        }

        $failed = $modelq->getTaskHistory(100, 0, 'failed');
        $this->assertCount(1, $failed);
        $this->assertEquals('filter_2', $failed[0]['task_id']);
    }

    public function testGetTaskHistoryWithNameFilter(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'name_1', 'task_name' => 'process_image', 'status' => 'completed', 'created_at' => $now - 100],
            ['task_id' => 'name_2', 'task_name' => 'generate_text', 'status' => 'completed', 'created_at' => $now - 50],
            ['task_id' => 'name_3', 'task_name' => 'process_image', 'status' => 'completed', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $imageTasks = $modelq->getTaskHistory(100, 0, null, 'process_image');
        $this->assertCount(2, $imageTasks);
        foreach ($imageTasks as $t) {
            $this->assertEquals('process_image', $t['task_name']);
        }
    }

    public function testGetFailedTasks(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'fail_1', 'task_name' => 'task_a', 'status' => 'failed', 'created_at' => $now - 100],
            ['task_id' => 'fail_2', 'task_name' => 'task_b', 'status' => 'completed', 'created_at' => $now - 50],
            ['task_id' => 'fail_3', 'task_name' => 'task_a', 'status' => 'failed', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $failed = $modelq->getFailedTasks();
        $this->assertCount(2, $failed);
        foreach ($failed as $t) {
            $this->assertEquals('failed', $t['status']);
        }
    }

    public function testGetCompletedTasks(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'comp_1', 'task_name' => 'task_a', 'status' => 'completed', 'created_at' => $now - 100],
            ['task_id' => 'comp_2', 'task_name' => 'task_b', 'status' => 'failed', 'created_at' => $now - 50],
            ['task_id' => 'comp_3', 'task_name' => 'task_a', 'status' => 'completed', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $completed = $modelq->getCompletedTasks();
        $this->assertCount(2, $completed);
        foreach ($completed as $t) {
            $this->assertEquals('completed', $t['status']);
        }
    }

    public function testGetTasksByName(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'byname_1', 'task_name' => 'process_image', 'status' => 'completed', 'created_at' => $now - 100],
            ['task_id' => 'byname_2', 'task_name' => 'generate_text', 'status' => 'completed', 'created_at' => $now - 50],
            ['task_id' => 'byname_3', 'task_name' => 'process_image', 'status' => 'failed', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $imageTasks = $modelq->getTasksByName('process_image');
        $this->assertCount(2, $imageTasks);
        foreach ($imageTasks as $t) {
            $this->assertEquals('process_image', $t['task_name']);
        }
    }

    public function testGetTaskStats(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'stats_1', 'task_name' => 'process_image', 'status' => 'completed', 'created_at' => $now - 100],
            ['task_id' => 'stats_2', 'task_name' => 'process_image', 'status' => 'failed', 'created_at' => $now - 50, 'error' => ['message' => 'Test error']],
            ['task_id' => 'stats_3', 'task_name' => 'generate_text', 'status' => 'completed', 'created_at' => $now],
            ['task_id' => 'stats_4', 'task_name' => 'process_image', 'status' => 'queued', 'created_at' => $now + 10],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $stats = $modelq->getTaskStats();

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(2, $stats['by_status']['completed']);
        $this->assertEquals(1, $stats['by_status']['failed']);
        $this->assertEquals(1, $stats['by_status']['queued']);
        $this->assertEquals(3, $stats['by_task_name']['process_image']['total']);
        $this->assertEquals(1, $stats['by_task_name']['process_image']['completed']);
        $this->assertEquals(1, $stats['by_task_name']['process_image']['failed']);
        $this->assertEquals(1, $stats['by_task_name']['generate_text']['total']);
        $this->assertCount(1, $stats['failed_tasks']);
        $this->assertEquals('Test error', $stats['failed_tasks'][0]['error']);
    }

    public function testGetTaskHistoryCount(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        for ($i = 0; $i < 5; $i++) {
            $task = ['task_id' => "count_$i", 'task_name' => 'test', 'status' => 'completed', 'created_at' => $now + $i];
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        $count = $modelq->getTaskHistoryCount();
        $this->assertEquals(5, $count);
    }

    public function testClearTaskHistory(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();
        $tasks = [
            ['task_id' => 'clear_1', 'task_name' => 'test', 'status' => 'completed', 'created_at' => $now - 1000],
            ['task_id' => 'clear_2', 'task_name' => 'test', 'status' => 'completed', 'created_at' => $now - 500],
            ['task_id' => 'clear_3', 'task_name' => 'test', 'status' => 'completed', 'created_at' => $now],
        ];

        foreach ($tasks as $task) {
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        // Clear tasks older than 600 seconds
        $removed = $modelq->clearTaskHistory(600);
        $this->assertEquals(1, $removed);

        // Verify remaining
        $this->assertEquals(0, $redis->exists("task_history:clear_1"));
        $this->assertEquals(1, $redis->exists("task_history:clear_2"));
        $this->assertEquals(1, $redis->exists("task_history:clear_3"));
    }

    public function testTaskHistoryWithErrorDetails(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $taskData = [
            'task_id' => 'error-task-1',
            'task_name' => 'failing_task',
            'status' => 'failed',
            'created_at' => time(),
            'error' => [
                'message' => 'Division by zero',
                'type' => 'DivisionByZeroError',
                'file' => '/app/tasks.php',
                'line' => 42,
                'trace' => 'Traceback...',
            ],
        ];

        $redis->set("task_history:{$taskData['task_id']}", json_encode($taskData));
        $redis->zAdd('task_history', $taskData['created_at'], $taskData['task_id']);

        $details = $modelq->getTaskDetails('error-task-1');

        $this->assertNotNull($details);
        $this->assertArrayHasKey('error', $details);
        $this->assertEquals('Division by zero', $details['error']['message']);
        $this->assertEquals('DivisionByZeroError', $details['error']['type']);
        $this->assertEquals('/app/tasks.php', $details['error']['file']);
        $this->assertEquals(42, $details['error']['line']);
    }

    // -------------------------------------------------------------------------
    // TTL and Cleanup Tests
    // -------------------------------------------------------------------------

    public function testCleanupExpiredTasks(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();

        // Create an old task (expired)
        $oldTask = [
            'task_id' => 'expired-task',
            'task_name' => 'test',
            'status' => 'queued',
            'created_at' => $now - 100000, // Very old
        ];
        $redis->rPush('ml_tasks', json_encode($oldTask));

        // Create a fresh task
        $freshTask = [
            'task_id' => 'fresh-task',
            'task_name' => 'test',
            'status' => 'queued',
            'created_at' => $now,
        ];
        $redis->rPush('ml_tasks', json_encode($freshTask));

        // Cleanup expired tasks
        $removed = $modelq->cleanupExpiredTasks();
        $this->assertEquals(1, $removed);

        // Verify fresh task is still in queue
        $queued = $modelq->getAllQueuedTasks();
        $taskIds = array_column($queued, 'task_id');
        $this->assertContains('fresh-task', $taskIds);
        $this->assertNotContains('expired-task', $taskIds);
    }

    public function testConfigurableTaskTtl(): void
    {
        // Custom TTL of 1 hour
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379, taskTtl: 3600);
        $this->assertEquals(3600, $modelq->getTaskTtl());
    }

    public function testConfigurableHistoryRetention(): void
    {
        // Custom retention of 1 hour
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379, taskHistoryRetention: 3600);
        $this->assertEquals(3600, $modelq->getTaskHistoryRetention());
    }
}
