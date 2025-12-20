<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Middleware\Middleware;
use ModelsLab\ModelQ\Task\Task;

echo "=== ModelQ PHP Manual Test Suite ===\n\n";

// Track test results
$passed = 0;
$failed = 0;

function test(string $name, callable $test): void
{
    global $passed, $failed;
    echo "Testing: {$name}... ";
    try {
        $result = $test();
        if ($result === true) {
            echo "PASSED\n";
            $passed++;
        } else {
            echo "FAILED - {$result}\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "FAILED - Exception: {$e->getMessage()}\n";
        $failed++;
    }
}

// Clean up Redis before tests
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushDb();
echo "Redis DB flushed.\n\n";

// ============================================
// Test 1: Basic ModelQ instantiation
// ============================================
test("ModelQ instantiation", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    return $modelq instanceof ModelQ;
});

// ============================================
// Test 2: Task registration
// ============================================
test("Task registration", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $modelq->task('test_task', fn($data) => ['result' => 'success']);
    return in_array('test_task', $modelq->getAllowedTasks());
});

// ============================================
// Test 3: Server registration in Redis
// ============================================
test("Server registration", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $servers = $modelq->getRegisteredServerIds();
    return count($servers) > 0;
});

// ============================================
// Test 4: Task enqueueing
// ============================================
test("Task enqueueing", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $modelq->task('enqueue_test', fn($data) => $data);

    $task = $modelq->enqueue('enqueue_test', ['foo' => 'bar']);

    return !empty($task->taskId) && $task->status === 'queued';
});

// ============================================
// Test 5: Get all queued tasks
// ============================================
test("Get queued tasks", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $modelq->task('queue_list_test', fn($data) => $data);

    $task = $modelq->enqueue('queue_list_test', ['test' => true]);
    $queued = $modelq->getAllQueuedTasks();

    $found = false;
    foreach ($queued as $q) {
        if ($q['task_id'] === $task->taskId) {
            $found = true;
            break;
        }
    }
    return $found;
});

// ============================================
// Test 6: Get task status
// ============================================
test("Get task status", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $modelq->task('status_test', fn($data) => $data);

    $task = $modelq->enqueue('status_test', ['data' => 1]);
    $status = $modelq->getTaskStatus($task->taskId);

    return $status === 'queued';
});

// ============================================
// Test 7: Remove task from queue
// ============================================
test("Remove task from queue", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $modelq->task('remove_test', fn($data) => $data);

    $task = $modelq->enqueue('remove_test', ['data' => 1]);
    $removed = $modelq->removeTaskFromQueue($task->taskId);

    // Verify it's gone
    $queued = $modelq->getAllQueuedTasks();
    $found = false;
    foreach ($queued as $q) {
        if ($q['task_id'] === $task->taskId) {
            $found = true;
            break;
        }
    }

    return $removed && !$found;
});

// ============================================
// Test 8: Clear entire queue
// ============================================
test("Clear queue", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $modelq->task('clear_test', fn($data) => $data);

    // Add some tasks
    $modelq->enqueue('clear_test', ['a' => 1]);
    $modelq->enqueue('clear_test', ['b' => 2]);

    // Clear
    $modelq->deleteQueue();

    $queued = $modelq->getAllQueuedTasks();
    return count($queued) === 0;
});

// ============================================
// Test 9: Task serialization (to/from array)
// ============================================
test("Task serialization", function () {
    $task = new Task('serialize_test', ['data' => 'test']);
    $array = $task->toArray();

    $restored = Task::fromArray($array);

    return $restored->taskId === $task->taskId
        && $restored->taskName === $task->taskName
        && $restored->payload === $task->payload;
});

// ============================================
// Test 10: Middleware registration
// ============================================
class TestMiddleware extends Middleware
{
    public static bool $beforeEnqueueCalled = false;
    public static bool $afterEnqueueCalled = false;

    public function beforeEnqueue(?Task $task): void
    {
        self::$beforeEnqueueCalled = true;
    }

    public function afterEnqueue(?Task $task): void
    {
        self::$afterEnqueueCalled = true;
    }
}

test("Middleware hooks", function () {
    TestMiddleware::$beforeEnqueueCalled = false;
    TestMiddleware::$afterEnqueueCalled = false;

    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $modelq->setMiddleware(new TestMiddleware());
    $modelq->task('middleware_test', fn($data) => $data);

    $modelq->enqueue('middleware_test', ['test' => true]);

    return TestMiddleware::$beforeEnqueueCalled && TestMiddleware::$afterEnqueueCalled;
});

// ============================================
// Test 11: Delayed task enqueueing
// ============================================
test("Delayed task enqueueing", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

    $taskDict = [
        'task_id' => 'delayed-123',
        'task_name' => 'delayed_test',
        'payload' => ['delay' => true],
        'status' => 'queued',
    ];

    $modelq->enqueueDelayedTask($taskDict, 60);

    // Check it's in delayed_tasks sorted set
    $redis = $modelq->getRedisClient();
    $delayed = $redis->zRange('delayed_tasks', 0, -1);

    $found = false;
    foreach ($delayed as $item) {
        $data = json_decode($item, true);
        if ($data['task_id'] === 'delayed-123') {
            $found = true;
            break;
        }
    }

    return $found;
});

// ============================================
// Test 12: Processing tasks count
// ============================================
test("Processing tasks tracking", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

    // Manually add a processing task
    $redis = $modelq->getRedisClient();
    $redis->sAdd('processing_tasks', 'test-processing-123');
    $redis->set('task:test-processing-123', json_encode([
        'task_id' => 'test-processing-123',
        'task_name' => 'test',
        'status' => 'processing',
        'payload' => [],
    ]));

    $processing = $modelq->getProcessingTasks();

    // Cleanup
    $redis->sRem('processing_tasks', 'test-processing-123');
    $redis->del('task:test-processing-123');

    return count($processing) === 1;
});

// ============================================
// Test 13: Multiple tasks with different options
// ============================================
test("Multiple task types", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);

    $modelq->task('task_with_timeout', fn($data) => $data, ['timeout' => 30]);
    $modelq->task('task_with_retries', fn($data) => $data, ['retries' => 3]);
    $modelq->task('task_with_stream', fn($data) => $data, ['stream' => true]);

    $tasks = $modelq->getAllowedTasks();

    return in_array('task_with_timeout', $tasks)
        && in_array('task_with_retries', $tasks)
        && in_array('task_with_stream', $tasks);
});

// ============================================
// Test 14: Redis client getter
// ============================================
test("Redis client access", function () {
    $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
    $redis = $modelq->getRedisClient();

    // Try a simple operation
    $redis->set('modelq_test_key', 'test_value');
    $value = $redis->get('modelq_test_key');
    $redis->del('modelq_test_key');

    return $value === 'test_value';
});

// ============================================
// Test 15: Task with custom Redis client
// ============================================
test("Custom Redis client", function () {
    $customRedis = new Redis();
    $customRedis->connect('127.0.0.1', 6379);
    $customRedis->select(1);

    $modelq = new ModelQ(redisClient: $customRedis);

    $modelq->task('custom_redis_test', fn($data) => $data);

    return in_array('custom_redis_test', $modelq->getAllowedTasks());
});

// ============================================
// Summary
// ============================================
echo "\n=== Test Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total: " . ($passed + $failed) . "\n";

if ($failed > 0) {
    exit(1);
}

echo "\nAll tests passed!\n";
