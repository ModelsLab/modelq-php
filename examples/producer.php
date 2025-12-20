<?php

/**
 * ModelQ PHP - Producer Example
 *
 * This example demonstrates how to enqueue tasks and wait for results.
 *
 * First, start the worker in another terminal:
 *   $ php examples/worker.php
 *
 * Then run this producer:
 *   $ php examples/producer.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Exception\TaskTimeoutException;
use ModelsLab\ModelQ\Exception\TaskProcessingException;

echo "=== ModelQ PHP Producer Example ===\n\n";

// Initialize ModelQ
$modelq = new ModelQ(
    host: '127.0.0.1',
    port: 6379
);

// Register tasks (handlers are empty on producer side)
$modelq->task('add_numbers', fn($data) => null);
$modelq->task('multiply', fn($data) => null);
$modelq->task('process_text', fn($data) => null);
$modelq->task('echo_data', fn($data) => null);
$modelq->task('process_image', fn($data) => null);

echo "Make sure the worker is running:\n";
echo "  $ php examples/worker.php\n\n";

// ============================================
// Example 1: Simple Task with Result
// ============================================
echo "1. Simple Addition Task\n";

try {
    $task = $modelq->enqueue('add_numbers', ['a' => 42, 'b' => 58]);
    echo "   Task ID: {$task->taskId}\n";
    echo "   Waiting for result...\n";

    $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
    echo "   Result: " . json_encode($result) . "\n\n";
} catch (TaskTimeoutException $e) {
    echo "   Timeout! Is the worker running?\n\n";
}

// ============================================
// Example 2: Text Processing
// ============================================
echo "2. Text Processing Task\n";

try {
    $task = $modelq->enqueue('process_text', [
        'text' => 'ModelQ is a lightweight task queue for PHP'
    ]);
    echo "   Task ID: {$task->taskId}\n";

    $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
    echo "   Original: {$result['original']}\n";
    echo "   Uppercase: {$result['uppercase']}\n";
    echo "   Word count: {$result['word_count']}\n";
    echo "   Char count: {$result['char_count']}\n\n";
} catch (TaskTimeoutException $e) {
    echo "   Timeout! Is the worker running?\n\n";
}

// ============================================
// Example 3: Multiple Concurrent Tasks
// ============================================
echo "3. Multiple Concurrent Tasks\n";

$tasks = [];
$operations = [
    ['a' => 10, 'b' => 20],
    ['a' => 30, 'b' => 40],
    ['a' => 50, 'b' => 60],
    ['a' => 70, 'b' => 80],
    ['a' => 90, 'b' => 100],
];

echo "   Enqueueing 5 tasks...\n";

foreach ($operations as $op) {
    $tasks[] = $modelq->enqueue('add_numbers', $op);
}

echo "   Waiting for all results...\n";

try {
    foreach ($tasks as $index => $task) {
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
        $op = $operations[$index];
        echo "   Task {$index}: {$op['a']} + {$op['b']} = {$result['sum']}\n";
    }
    echo "\n";
} catch (TaskTimeoutException $e) {
    echo "   Timeout! Is the worker running?\n\n";
}

// ============================================
// Example 4: Echo Data (Complex Payload)
// ============================================
echo "4. Complex Payload (Echo)\n";

try {
    $complexData = [
        'user' => [
            'id' => 12345,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ],
        'items' => [
            ['product' => 'Widget', 'qty' => 2],
            ['product' => 'Gadget', 'qty' => 1],
        ],
        'metadata' => [
            'source' => 'api',
            'version' => '1.0',
        ],
    ];

    $task = $modelq->enqueue('echo_data', $complexData);
    echo "   Task ID: {$task->taskId}\n";

    $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
    echo "   Result matches input: " . ($result === $complexData ? 'Yes' : 'No') . "\n\n";
} catch (TaskTimeoutException $e) {
    echo "   Timeout! Is the worker running?\n\n";
}

// ============================================
// Example 5: Image Processing Simulation
// ============================================
echo "5. Image Processing Task\n";

try {
    $task = $modelq->enqueue('process_image', [
        'url' => 'https://example.com/image.jpg',
        'width' => 1920,
        'height' => 1080,
    ]);
    echo "   Task ID: {$task->taskId}\n";
    echo "   Processing image...\n";

    $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
    echo "   URL: {$result['url']}\n";
    echo "   Dimensions: {$result['width']}x{$result['height']}\n";
    echo "   Format: {$result['format']}\n";
    echo "   Processed: " . ($result['processed'] ? 'Yes' : 'No') . "\n\n";
} catch (TaskTimeoutException $e) {
    echo "   Timeout! Is the worker running?\n\n";
}

// ============================================
// Example 6: Error Handling
// ============================================
echo "6. Error Handling\n";

try {
    $task = $modelq->enqueue('nonexistent_task', []);
    $result = $task->getResult($modelq->getRedisClient(), timeout: 5);
} catch (TaskTimeoutException $e) {
    echo "   Caught TaskTimeoutException: Task {$e->taskId} timed out\n";
} catch (TaskProcessingException $e) {
    echo "   Caught TaskProcessingException: {$e->getMessage()}\n";
}

echo "\n=== Producer Example Complete ===\n";
