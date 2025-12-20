<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

echo "=== ModelQ Worker End-to-End Test ===\n\n";

// Clean Redis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushDb();
echo "Redis DB flushed.\n\n";

// This file will be used both as worker and as test runner
$isWorker = isset($argv[1]) && $argv[1] === '--worker';

if ($isWorker) {
    // ============================================
    // WORKER MODE
    // ============================================
    echo "Starting as WORKER...\n";

    $modelq = new ModelQ(host: 'localhost', port: 6379);

    // Register task handlers
    $modelq->task('add_numbers', function (array $data): array {
        $a = $data['a'] ?? 0;
        $b = $data['b'] ?? 0;
        return ['sum' => $a + $b];
    });

    $modelq->task('multiply', function (array $data): int {
        $a = $data['a'] ?? 0;
        $b = $data['b'] ?? 0;
        return $a * $b;
    });

    $modelq->task('slow_task', function (array $data): string {
        sleep(1);
        return 'completed after delay';
    });

    $modelq->task('echo_data', function (array $data): array {
        return $data;
    });

    // Run worker (will be stopped by parent process)
    $modelq->startWorkers(1);
} else {
    // ============================================
    // TEST RUNNER MODE
    // ============================================
    echo "Starting as TEST RUNNER...\n";

    // Start worker in background
    $workerCmd = "php " . escapeshellarg(__FILE__) . " --worker > /tmp/modelq_worker.log 2>&1 &";
    exec($workerCmd);
    echo "Worker started in background.\n";

    // Give worker time to initialize
    sleep(2);

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
                echo "FAILED - " . (is_string($result) ? $result : "returned false") . "\n";
                $failed++;
            }
        } catch (Throwable $e) {
            echo "FAILED - Exception: {$e->getMessage()}\n";
            $failed++;
        }
    }

    // Create producer ModelQ instance
    $modelq = new ModelQ(host: 'localhost', port: 6379);

    // Register tasks (just for enqueueing - handlers don't matter on producer side)
    $modelq->task('add_numbers', fn($data) => null);
    $modelq->task('multiply', fn($data) => null);
    $modelq->task('slow_task', fn($data) => null);
    $modelq->task('echo_data', fn($data) => null);

    // ============================================
    // Test 1: Simple addition task
    // ============================================
    test("Add numbers task", function () use ($modelq) {
        $task = $modelq->enqueue('add_numbers', ['a' => 5, 'b' => 3]);
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
        return is_array($result) && ($result['sum'] ?? null) === 8;
    });

    // ============================================
    // Test 2: Multiplication task
    // ============================================
    test("Multiply task", function () use ($modelq) {
        $task = $modelq->enqueue('multiply', ['a' => 7, 'b' => 6]);
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
        return $result === 42;
    });

    // ============================================
    // Test 3: Echo data task
    // ============================================
    test("Echo data task", function () use ($modelq) {
        $testData = ['name' => 'ModelQ', 'version' => '0.1.0', 'nested' => ['a' => 1]];
        $task = $modelq->enqueue('echo_data', $testData);
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
        return $result === $testData;
    });

    // ============================================
    // Test 4: Slow task (tests timeout handling)
    // ============================================
    test("Slow task completion", function () use ($modelq) {
        $task = $modelq->enqueue('slow_task', []);
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
        return $result === 'completed after delay';
    });

    // ============================================
    // Test 5: Multiple concurrent tasks
    // ============================================
    test("Multiple concurrent tasks", function () use ($modelq) {
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $modelq->enqueue('add_numbers', ['a' => $i, 'b' => $i * 2]);
        }

        $results = [];
        foreach ($tasks as $i => $task) {
            $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
            $results[] = $result['sum'] ?? -1;
        }

        // Expected: 0, 3, 6, 9, 12
        $expected = [0, 3, 6, 9, 12];
        return $results === $expected;
    });

    // ============================================
    // Test 6: Task status transitions
    // ============================================
    test("Task status after completion", function () use ($modelq) {
        $task = $modelq->enqueue('add_numbers', ['a' => 1, 'b' => 1]);
        $task->getResult($modelq->getRedisClient(), timeout: 10);

        // Check status is completed
        $status = $modelq->getTaskStatus($task->taskId);
        return $status === 'completed';
    });

    // ============================================
    // Cleanup: Kill worker
    // ============================================
    echo "\nStopping worker...\n";
    exec("pkill -f 'php.*test_worker.php.*--worker' 2>/dev/null");

    // ============================================
    // Summary
    // ============================================
    echo "\n=== Test Results ===\n";
    echo "Passed: {$passed}\n";
    echo "Failed: {$failed}\n";
    echo "Total: " . ($passed + $failed) . "\n";

    if ($failed > 0) {
        // Show worker log on failure
        echo "\n=== Worker Log ===\n";
        echo file_get_contents('/tmp/modelq_worker.log') ?: "(empty)";
        exit(1);
    }

    echo "\nAll end-to-end tests passed!\n";
}
