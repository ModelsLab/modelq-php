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
}
