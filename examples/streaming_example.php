<?php

/**
 * ModelQ PHP - Streaming Tasks Example
 *
 * This example demonstrates how to use streaming tasks for
 * real-time output, such as ML text generation or progress updates.
 *
 * First, start the worker in another terminal:
 *   $ php examples/worker.php
 *
 * Then run this example:
 *   $ php examples/streaming_example.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

echo "=== ModelQ PHP Streaming Example ===\n\n";

// Initialize ModelQ
$modelq = new ModelQ(
    host: '127.0.0.1',
    port: 6379
);

// Register streaming tasks (handlers can be empty on producer side)
$modelq->task('generate_text', fn($data) => null, ['stream' => true]);
$modelq->task('stream_numbers', fn($data) => null, ['stream' => true]);

// ============================================
// Example 1: Text Generation Streaming
// ============================================
echo "1. Text Generation Streaming\n";
echo "   Simulating ML text generation with word-by-word output:\n\n";

$textTask = $modelq->enqueue('generate_text', [
    'prompt' => 'Tell me about ModelQ'
]);

echo "   Task ID: {$textTask->taskId}\n";
echo "   Output: ";

try {
    foreach ($textTask->getStream($modelq->getRedisClient()) as $word) {
        echo $word;
        flush(); // Flush output for real-time display
    }
    echo "\n\n";
    echo "   Status: {$textTask->status}\n";
    echo "   Combined result: {$textTask->combinedResult}\n";
} catch (Exception $e) {
    echo "\n   Error: {$e->getMessage()}\n";
    echo "   Make sure the worker is running: php examples/worker.php\n";
}

// ============================================
// Example 2: Number Streaming with Progress
// ============================================
echo "\n2. Number Streaming with Progress\n";
echo "   Streaming structured data (numbers with squares):\n\n";

$numberTask = $modelq->enqueue('stream_numbers', ['count' => 5]);

echo "   Task ID: {$numberTask->taskId}\n";
echo "   Progress:\n";

try {
    foreach ($numberTask->getStream($modelq->getRedisClient()) as $item) {
        if (is_array($item)) {
            echo "   - Number: {$item['number']}, Square: {$item['square']}\n";
        } else {
            echo "   - {$item}\n";
        }
    }
    echo "\n   Streaming complete!\n";
} catch (Exception $e) {
    echo "\n   Error: {$e->getMessage()}\n";
    echo "   Make sure the worker is running: php examples/worker.php\n";
}

// ============================================
// Example 3: Creating Custom Streaming Tasks
// ============================================
echo "\n3. Creating Custom Streaming Tasks\n";
echo "   Here's how to create a streaming task handler:\n\n";

$code = <<<'CODE'
// In your worker:
$modelq->task('my_stream_task', function (array $data): Generator {
    $items = $data['items'] ?? [];

    foreach ($items as $index => $item) {
        // Process each item
        $result = processItem($item);

        // Yield results as they become available
        yield [
            'index' => $index,
            'item' => $item,
            'result' => $result,
            'progress' => ($index + 1) / count($items) * 100,
        ];
    }
}, ['stream' => true]);

// In your producer:
$task = $modelq->enqueue('my_stream_task', ['items' => [1, 2, 3]]);

foreach ($task->getStream($modelq->getRedisClient()) as $result) {
    echo "Progress: {$result['progress']}%\n";
}
CODE;

echo $code . "\n";

echo "\n=== Streaming Example Complete ===\n";
