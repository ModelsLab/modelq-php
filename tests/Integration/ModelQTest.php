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

    public function testEnqueueWithAdditionalParams(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('additional_params_test', fn($data) => $data);

        $additionalParams = [
            'user_id' => 'user_123',
            'priority' => 'high',
            'metadata' => ['source' => 'api'],
        ];

        $task = $modelq->enqueue(
            'additional_params_test',
            ['key' => 'value'],
            additionalParams: $additionalParams
        );

        $this->assertInstanceOf(Task::class, $task);
        $this->assertEquals($additionalParams, $task->additionalParams);

        // Verify additional_params are included in toArray output
        $array = $task->toArray();
        $this->assertEquals('user_123', $array['user_id']);
        $this->assertEquals('high', $array['priority']);
        $this->assertEquals(['source' => 'api'], $array['metadata']);

        // Verify task data stored in Redis includes additional_params
        $redis = $modelq->getRedisClient();
        $taskData = json_decode($redis->get("task:{$task->taskId}"), true);
        $this->assertEquals('user_123', $taskData['user_id']);
        $this->assertEquals('high', $taskData['priority']);
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

    // -------------------------------------------------------------------------
    // Worker Management Tests (getWorkers, getWorker)
    // -------------------------------------------------------------------------

    public function testGetWorkers(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379, serverId: 'test-worker-1');
        $modelq->task('test_task', fn($data) => $data);

        $workers = $modelq->getWorkers();

        $this->assertIsArray($workers);
        $this->assertArrayHasKey('test-worker-1', $workers);
        $this->assertEquals('test-worker-1', $workers['test-worker-1']['worker_id']);
        $this->assertArrayHasKey('status', $workers['test-worker-1']);
        $this->assertArrayHasKey('allowed_tasks', $workers['test-worker-1']);
        $this->assertArrayHasKey('last_heartbeat', $workers['test-worker-1']);
    }

    public function testGetWorkersMultiple(): void
    {
        $redis = $this->redis;

        // Simulate multiple workers
        $workers = [
            'worker-1' => [
                'status' => 'idle',
                'allowed_tasks' => ['task_a', 'task_b'],
                'last_heartbeat' => microtime(true),
                'system_info' => ['cpu' => '8 cores', 'ram' => '16GB'],
            ],
            'worker-2' => [
                'status' => 'busy',
                'allowed_tasks' => ['task_c'],
                'last_heartbeat' => microtime(true),
                'hostname' => 'gpu-server-1',
            ],
        ];

        foreach ($workers as $id => $data) {
            $redis->hSet('servers', $id, json_encode($data));
        }

        $modelq = new ModelQ(redisClient: $this->redis, serverId: 'test-main');
        $result = $modelq->getWorkers();

        $this->assertArrayHasKey('worker-1', $result);
        $this->assertArrayHasKey('worker-2', $result);
        $this->assertEquals('idle', $result['worker-1']['status']);
        $this->assertEquals('busy', $result['worker-2']['status']);
        $this->assertEquals(['task_a', 'task_b'], $result['worker-1']['allowed_tasks']);
        $this->assertEquals('gpu-server-1', $result['worker-2']['hostname']);
    }

    public function testGetWorker(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379, serverId: 'specific-worker');
        $modelq->task('my_task', fn($data) => $data);

        $worker = $modelq->getWorker('specific-worker');

        $this->assertNotNull($worker);
        $this->assertEquals('specific-worker', $worker['worker_id']);
        $this->assertContains('my_task', $worker['allowed_tasks']);
    }

    public function testGetWorkerNotFound(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $worker = $modelq->getWorker('nonexistent-worker');

        $this->assertNull($worker);
    }

    public function testGetWorkerWithSystemInfo(): void
    {
        $redis = $this->redis;

        $workerData = [
            'status' => 'idle',
            'allowed_tasks' => ['gpu_task'],
            'last_heartbeat' => microtime(true),
            'system_info' => [
                'cpu' => 'AMD Ryzen 9',
                'ram' => '64GB',
                'gpu' => 'NVIDIA RTX 4090',
            ],
            'hostname' => 'ml-server-01',
            'python_version' => '3.11.0',
        ];

        $redis->hSet('servers', 'gpu-worker', json_encode($workerData));

        $modelq = new ModelQ(redisClient: $this->redis);
        $worker = $modelq->getWorker('gpu-worker');

        $this->assertNotNull($worker);
        $this->assertEquals('gpu-worker', $worker['worker_id']);
        $this->assertEquals('ml-server-01', $worker['hostname']);
        $this->assertEquals('3.11.0', $worker['python_version']);
        $this->assertIsArray($worker['system_info']);
        $this->assertEquals('NVIDIA RTX 4090', $worker['system_info']['gpu']);
    }

    public function testGetWorkersWithMalformedJson(): void
    {
        $redis = $this->redis;

        // Add a valid worker
        $redis->hSet('servers', 'valid-worker', json_encode([
            'status' => 'idle',
            'allowed_tasks' => [],
            'last_heartbeat' => microtime(true),
        ]));

        // Add malformed JSON (should be skipped gracefully)
        $redis->hSet('servers', 'malformed-worker', 'not valid json {{{');

        $modelq = new ModelQ(redisClient: $this->redis, serverId: 'test-server');
        $workers = $modelq->getWorkers();

        // Should still return valid workers, skip malformed ones
        $this->assertArrayHasKey('valid-worker', $workers);
        // Malformed worker should not crash, may or may not be in results
    }

    // -------------------------------------------------------------------------
    // Task Redis Methods Tests (getStatus, getProgress, isCancelled on Task)
    // -------------------------------------------------------------------------

    public function testTaskGetStatus(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        // Create a task and set its status
        $task = new Task('status_check', ['data' => 'test']);
        $redis->set("task:{$task->taskId}", json_encode([
            'task_id' => $task->taskId,
            'task_name' => 'status_check',
            'status' => 'processing',
            'payload' => [],
        ]));

        $status = $task->getStatus($redis);

        $this->assertEquals('processing', $status);
    }

    public function testTaskGetStatusNotFound(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $task = new Task('nonexistent', []);

        $status = $task->getStatus($redis);

        $this->assertNull($status);
    }

    public function testTaskGetProgress(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $task = new Task('progress_check', []);

        // Set progress data
        $progressData = [
            'progress' => 0.75,
            'message' => 'Processing step 3 of 4',
            'updated_at' => microtime(true),
        ];
        $redis->set("task:{$task->taskId}:progress", json_encode($progressData));

        $progress = $task->getProgress($redis);

        $this->assertNotNull($progress);
        $this->assertEquals(0.75, $progress['progress']);
        $this->assertEquals('Processing step 3 of 4', $progress['message']);
    }

    public function testTaskGetProgressNotFound(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $task = new Task('no_progress', []);

        $progress = $task->getProgress($redis);

        $this->assertNull($progress);
    }

    public function testTaskIsCancelled(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $task = new Task('cancel_check', []);

        // Not cancelled initially
        $this->assertFalse($task->isCancelled($redis));

        // Set cancellation flag
        $redis->set("task:{$task->taskId}:cancelled", '1');

        $this->assertTrue($task->isCancelled($redis));
    }

    // -------------------------------------------------------------------------
    // Edge Cases and Error Handling Tests
    // -------------------------------------------------------------------------

    public function testEnqueueWithEmptyPayload(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('empty_payload_task', fn($data) => 'done');

        $task = $modelq->enqueue('empty_payload_task', []);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertEquals([], $task->payload['data']);
    }

    public function testEnqueueWithLargePayload(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('large_payload_task', fn($data) => count($data));

        // Create a large payload (1000 items)
        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["key_$i"] = str_repeat("value_$i", 100);
        }

        $task = $modelq->enqueue('large_payload_task', $largeData);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertCount(1000, $task->payload['data']);

        // Verify it's stored in Redis
        $redis = $modelq->getRedisClient();
        $storedTask = json_decode($redis->get("task:{$task->taskId}"), true);
        $this->assertNotNull($storedTask);
        $this->assertCount(1000, $storedTask['payload']['data']);
    }

    public function testEnqueueWithSpecialCharacters(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('unicode_task', fn($data) => $data);

        $unicodeData = [
            'emoji' => '🚀🎉💻',
            'chinese' => '你好世界',
            'arabic' => 'مرحبا بالعالم',
            'special' => "Line1\nLine2\tTabbed\"Quoted\"",
            'null_byte' => "before\x00after",
        ];

        $task = $modelq->enqueue('unicode_task', $unicodeData);

        $this->assertInstanceOf(Task::class, $task);

        // Verify serialization/deserialization
        $redis = $modelq->getRedisClient();
        $storedTask = json_decode($redis->get("task:{$task->taskId}"), true);
        $this->assertEquals('🚀🎉💻', $storedTask['payload']['data']['emoji']);
        $this->assertEquals('你好世界', $storedTask['payload']['data']['chinese']);
    }

    public function testEnqueueWithNestedPayload(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('nested_task', fn($data) => $data);

        $nestedData = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'value' => 'deeply nested',
                        ],
                    ],
                ],
            ],
            'array' => [[1, 2, 3], [4, 5, 6]],
        ];

        $task = $modelq->enqueue('nested_task', $nestedData);

        $redis = $modelq->getRedisClient();
        $storedTask = json_decode($redis->get("task:{$task->taskId}"), true);
        $this->assertEquals(
            'deeply nested',
            $storedTask['payload']['data']['level1']['level2']['level3']['level4']['value']
        );
    }

    public function testCustomTaskId(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('custom_id_task', fn($data) => $data);

        $customId = 'my-custom-task-id-12345';
        $task = $modelq->enqueue('custom_id_task', ['test' => true], taskId: $customId);

        $this->assertEquals($customId, $task->taskId);

        // Verify in Redis
        $redis = $modelq->getRedisClient();
        $storedTask = $redis->get("task:$customId");
        $this->assertNotFalse($storedTask);
    }

    public function testRemoveNonexistentTask(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $removed = $modelq->removeTaskFromQueue('nonexistent-task-id');

        $this->assertFalse($removed);
    }

    public function testGetTaskStatusNonexistent(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $status = $modelq->getTaskStatus('nonexistent-task-id');

        $this->assertNull($status);
    }

    public function testCancelAlreadyCompletedTask(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        // Create a completed task
        $taskData = [
            'task_id' => 'completed-task-123',
            'task_name' => 'test',
            'status' => 'completed',
            'result' => 'success',
        ];
        $redis->set('task:completed-task-123', json_encode($taskData));

        $result = $modelq->cancelTask('completed-task-123');

        // Should still set cancellation flag
        $this->assertTrue($result);
        $this->assertTrue($modelq->isTaskCancelled('completed-task-123'));
    }

    public function testCancelAlreadyFailedTask(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        // Create a failed task
        $taskData = [
            'task_id' => 'failed-task-123',
            'task_name' => 'test',
            'status' => 'failed',
            'result' => 'Error occurred',
        ];
        $redis->set('task:failed-task-123', json_encode($taskData));

        $result = $modelq->cancelTask('failed-task-123');

        $this->assertTrue($result);
    }

    public function testMultipleCancellationCalls(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('cancel_multiple', fn($data) => $data);

        $task = $modelq->enqueue('cancel_multiple', []);

        // Cancel multiple times - should be idempotent
        $result1 = $modelq->cancelTask($task->taskId);
        $result2 = $modelq->cancelTask($task->taskId);
        $result3 = $modelq->cancelTask($task->taskId);

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertTrue($result3);
        $this->assertTrue($modelq->isTaskCancelled($task->taskId));
    }

    public function testGetTaskHistoryPagination(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();

        // Create 10 tasks
        for ($i = 0; $i < 10; $i++) {
            $task = [
                'task_id' => "page_task_$i",
                'task_name' => 'test',
                'status' => 'completed',
                'created_at' => $now + $i,
            ];
            $redis->set("task_history:{$task['task_id']}", json_encode($task));
            $redis->zAdd('task_history', $task['created_at'], $task['task_id']);
        }

        // Get first page (5 items)
        $page1 = $modelq->getTaskHistory(5, 0);
        $this->assertCount(5, $page1);
        $this->assertEquals('page_task_9', $page1[0]['task_id']); // Most recent first

        // Get second page
        $page2 = $modelq->getTaskHistory(5, 5);
        $this->assertCount(5, $page2);
        $this->assertEquals('page_task_4', $page2[0]['task_id']);

        // Get page beyond available
        $page3 = $modelq->getTaskHistory(5, 15);
        $this->assertEmpty($page3);
    }

    public function testGetProcessingTasksWithStaleEntries(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        // Add a task ID to processing set without corresponding data
        $redis->sAdd('processing_tasks', 'stale-task-id');

        // Add a valid processing task
        $redis->sAdd('processing_tasks', 'valid-task-id');
        $redis->set('task:valid-task-id', json_encode([
            'task_id' => 'valid-task-id',
            'task_name' => 'test',
            'status' => 'processing',
            'payload' => [],
        ]));

        $processing = $modelq->getProcessingTasks();

        // Should only return the valid task
        $this->assertCount(1, $processing);
        $this->assertEquals('valid-task-id', $processing[0]['task_id']);

        // Stale entry should be removed
        $this->assertFalse($redis->sIsMember('processing_tasks', 'stale-task-id'));
    }

    public function testGetProcessingTasksWithWrongStatus(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        // Add a task with non-processing status to processing set
        $redis->sAdd('processing_tasks', 'completed-but-in-set');
        $redis->set('task:completed-but-in-set', json_encode([
            'task_id' => 'completed-but-in-set',
            'task_name' => 'test',
            'status' => 'completed', // Wrong status
            'payload' => [],
        ]));

        $processing = $modelq->getProcessingTasks();

        // Should not return tasks with wrong status
        $this->assertEmpty($processing);

        // Entry should be removed from set
        $this->assertFalse($redis->sIsMember('processing_tasks', 'completed-but-in-set'));
    }

    public function testEnqueueWithCustomTaskIdAndAdditionalParams(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('full_options_task', fn($data) => $data);

        $task = $modelq->enqueue(
            'full_options_task',
            ['key' => 'value'],
            taskId: 'custom-full-id',
            additionalParams: ['user' => 'test-user', 'priority' => 1]
        );

        $this->assertEquals('custom-full-id', $task->taskId);
        $this->assertEquals('test-user', $task->additionalParams['user']);

        // Verify in Redis
        $redis = $modelq->getRedisClient();
        $storedTask = json_decode($redis->get('task:custom-full-id'), true);
        $this->assertEquals('test-user', $storedTask['user']);
        $this->assertEquals(1, $storedTask['priority']);
    }

    // -------------------------------------------------------------------------
    // Stop Method Test
    // -------------------------------------------------------------------------

    public function testStopMethod(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        // Use reflection to check internal state
        $reflection = new \ReflectionClass($modelq);
        $runningProperty = $reflection->getProperty('running');
        $runningProperty->setAccessible(true);

        // Initially not running
        $this->assertFalse($runningProperty->getValue($modelq));

        // Simulate starting
        $runningProperty->setValue($modelq, true);
        $this->assertTrue($runningProperty->getValue($modelq));

        // Stop should set running to false
        $modelq->stop();
        $this->assertFalse($runningProperty->getValue($modelq));
    }

    // -------------------------------------------------------------------------
    // Delayed Task Edge Cases
    // -------------------------------------------------------------------------

    public function testDelayedTaskWithZeroDelay(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $taskDict = [
            'task_id' => 'zero-delay-task',
            'task_name' => 'test',
            'payload' => [],
            'status' => 'queued',
        ];

        $modelq->enqueueDelayedTask($taskDict, 0);

        $delayed = $redis->zRange('delayed_tasks', 0, -1);
        $this->assertNotEmpty($delayed);
    }

    public function testDelayedTaskWithLargeDelay(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $taskDict = [
            'task_id' => 'large-delay-task',
            'task_name' => 'test',
            'payload' => [],
            'status' => 'queued',
        ];

        // 1 year delay
        $modelq->enqueueDelayedTask($taskDict, 365 * 24 * 60 * 60);

        $delayed = $redis->zRangeByScore('delayed_tasks', '-inf', '+inf', ['withscores' => true]);
        $this->assertNotEmpty($delayed);
    }

    // -------------------------------------------------------------------------
    // Task History Edge Cases
    // -------------------------------------------------------------------------

    public function testGetTaskHistoryWithMissingData(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        $now = time();

        // Add task ID to sorted set but not the data
        $redis->zAdd('task_history', $now, 'orphan-task-id');

        // Add a valid task
        $validTask = [
            'task_id' => 'valid-history-task',
            'task_name' => 'test',
            'status' => 'completed',
            'created_at' => $now + 1,
        ];
        $redis->set("task_history:{$validTask['task_id']}", json_encode($validTask));
        $redis->zAdd('task_history', $validTask['created_at'], $validTask['task_id']);

        $history = $modelq->getTaskHistory(10);

        // Should only return the valid task
        $this->assertCount(1, $history);
        $this->assertEquals('valid-history-task', $history[0]['task_id']);
    }

    public function testGetTaskStatsWithEmptyHistory(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

        $stats = $modelq->getTaskStats();

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['by_status']['queued']);
        $this->assertEquals(0, $stats['by_status']['completed']);
        $this->assertEquals(0, $stats['by_status']['failed']);
        $this->assertEmpty($stats['by_task_name']);
        $this->assertEmpty($stats['failed_tasks']);
    }

    public function testClearTaskHistoryWithNoOldTasks(): void
    {
        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $redis = $modelq->getRedisClient();

        // Add only fresh tasks
        $now = time();
        $task = [
            'task_id' => 'fresh-clear-task',
            'task_name' => 'test',
            'status' => 'completed',
            'created_at' => $now,
        ];
        $redis->set("task_history:{$task['task_id']}", json_encode($task));
        $redis->zAdd('task_history', $task['created_at'], $task['task_id']);

        // Try to clear tasks older than 1 hour - should remove nothing
        $removed = $modelq->clearTaskHistory(3600);

        $this->assertEquals(0, $removed);
        $this->assertEquals(1, $redis->exists("task_history:fresh-clear-task"));
    }
}
