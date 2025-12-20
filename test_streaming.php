<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

echo "=== ModelQ Streaming Test ===\n\n";

// Clean Redis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushDb();
echo "Redis DB flushed.\n\n";

$isWorker = isset($argv[1]) && $argv[1] === '--worker';

if ($isWorker) {
    // ============================================
    // WORKER MODE
    // ============================================
    echo "Starting as WORKER...\n";

    $modelq = new ModelQ(host: 'localhost', port: 6379);

    // Register streaming task
    $modelq->task('stream_words', function (array $data): \Generator {
        $sentence = $data['sentence'] ?? 'Hello World';
        $words = explode(' ', $sentence);

        foreach ($words as $word) {
            usleep(100000); // 100ms delay
            yield $word;
        }
    }, ['stream' => true]);

    $modelq->task('stream_numbers', function (array $data): \Generator {
        $count = $data['count'] ?? 5;

        for ($i = 1; $i <= $count; $i++) {
            usleep(50000); // 50ms delay
            yield ['number' => $i, 'square' => $i * $i];
        }
    }, ['stream' => true]);

    $modelq->startWorkers(1);
} else {
    // ============================================
    // TEST RUNNER MODE
    // ============================================
    echo "Starting as TEST RUNNER...\n";

    // Start worker in background
    $workerCmd = "php " . escapeshellarg(__FILE__) . " --worker > /tmp/modelq_stream_worker.log 2>&1 &";
    exec($workerCmd);
    echo "Worker started in background.\n";

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

    $modelq = new ModelQ(host: 'localhost', port: 6379);

    $modelq->task('stream_words', fn($data) => null, ['stream' => true]);
    $modelq->task('stream_numbers', fn($data) => null, ['stream' => true]);

    // ============================================
    // Test 1: Stream words
    // ============================================
    test("Stream words", function () use ($modelq) {
        $task = $modelq->enqueue('stream_words', ['sentence' => 'The quick brown fox']);

        $words = [];
        foreach ($task->getStream($modelq->getRedisClient()) as $word) {
            $words[] = $word;
            echo "."; // Progress indicator
        }
        echo " ";

        $expected = ['The', 'quick', 'brown', 'fox'];
        return $words === $expected;
    });

    // ============================================
    // Test 2: Stream numbers with objects
    // ============================================
    test("Stream numbers", function () use ($modelq) {
        $task = $modelq->enqueue('stream_numbers', ['count' => 3]);

        $numbers = [];
        foreach ($task->getStream($modelq->getRedisClient()) as $item) {
            $numbers[] = $item;
            echo ".";
        }
        echo " ";

        $expected = [
            ['number' => 1, 'square' => 1],
            ['number' => 2, 'square' => 4],
            ['number' => 3, 'square' => 9],
        ];
        return $numbers === $expected;
    });

    // ============================================
    // Test 3: Stream with combined result
    // ============================================
    test("Stream combined result", function () use ($modelq) {
        $task = $modelq->enqueue('stream_words', ['sentence' => 'Hello World']);

        $words = [];
        foreach ($task->getStream($modelq->getRedisClient()) as $word) {
            $words[] = $word;
        }

        // Check task status after stream completion
        return $task->status === 'completed' && count($words) === 2;
    });

    // Cleanup
    echo "\nStopping worker...\n";
    exec("pkill -f 'php.*test_streaming.php.*--worker' 2>/dev/null");

    // Summary
    echo "\n=== Test Results ===\n";
    echo "Passed: {$passed}\n";
    echo "Failed: {$failed}\n";
    echo "Total: " . ($passed + $failed) . "\n";

    if ($failed > 0) {
        echo "\n=== Worker Log ===\n";
        echo file_get_contents('/tmp/modelq_stream_worker.log') ?: "(empty)";
        exit(1);
    }

    echo "\nAll streaming tests passed!\n";
}
