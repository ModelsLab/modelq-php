<?php

/**
 * ModelQ PHP - Basic Usage Example
 *
 * This example demonstrates the core functionality of ModelQ:
 * - Task registration
 * - Enqueueing tasks
 * - Getting results
 * - Queue management
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Exception\TaskTimeoutException;
use ModelsLab\ModelQ\Exception\TaskProcessingException;

echo "=== ModelQ PHP Basic Usage Example ===\n\n";

// Initialize ModelQ with Redis connection
$modelq = new ModelQ(
    host: '127.0.0.1',
    port: 6379
);

// ============================================
// 1. Register Task Handlers
// ============================================
echo "1. Registering task handlers...\n";

// Simple addition task
$modelq->task('add_numbers', function (array $data): array {
    $a = $data['a'] ?? 0;
    $b = $data['b'] ?? 0;
    return ['sum' => $a + $b, 'operation' => "{$a} + {$b}"];
});

// String processing task
$modelq->task('process_text', function (array $data): array {
    $text = $data['text'] ?? '';
    return [
        'original' => $text,
        'uppercase' => strtoupper($text),
        'word_count' => str_word_count($text),
        'char_count' => strlen($text),
    ];
});

// Task with timeout option
$modelq->task('slow_operation', function (array $data): string {
    $delay = $data['delay'] ?? 1;
    sleep($delay);
    return "Completed after {$delay} second(s)";
}, ['timeout' => 30]);

echo "   Registered tasks: " . implode(', ', $modelq->getAllowedTasks()) . "\n\n";

// ============================================
// 2. Enqueue and Process Tasks
// ============================================
echo "2. Enqueueing tasks...\n";

// Enqueue an addition task
$addTask = $modelq->enqueue('add_numbers', ['a' => 15, 'b' => 27]);
echo "   Enqueued add_numbers task: {$addTask->taskId}\n";

// Enqueue a text processing task
$textTask = $modelq->enqueue('process_text', ['text' => 'Hello World from ModelQ']);
echo "   Enqueued process_text task: {$textTask->taskId}\n";

// ============================================
// 3. Check Task Status
// ============================================
echo "\n3. Checking task status...\n";

$status = $modelq->getTaskStatus($addTask->taskId);
echo "   add_numbers task status: {$status}\n";

// ============================================
// 4. List All Queued Tasks
// ============================================
echo "\n4. Listing queued tasks...\n";

$queuedTasks = $modelq->getAllQueuedTasks();
echo "   Total queued tasks: " . count($queuedTasks) . "\n";

foreach ($queuedTasks as $task) {
    echo "   - {$task['task_id']} ({$task['task_name']})\n";
}

// ============================================
// 5. Remove a Task from Queue
// ============================================
echo "\n5. Removing a task from queue...\n";

// Enqueue a task to remove
$taskToRemove = $modelq->enqueue('add_numbers', ['a' => 1, 'b' => 1]);
echo "   Enqueued task to remove: {$taskToRemove->taskId}\n";

$removed = $modelq->removeTaskFromQueue($taskToRemove->taskId);
echo "   Task removed: " . ($removed ? 'Yes' : 'No') . "\n";

// ============================================
// 6. Get Processing Tasks
// ============================================
echo "\n6. Checking processing tasks...\n";

$processingTasks = $modelq->getProcessingTasks();
echo "   Currently processing: " . count($processingTasks) . " task(s)\n";

// ============================================
// 7. Clear the Queue
// ============================================
echo "\n7. Clearing the queue...\n";

$modelq->deleteQueue();
$remainingTasks = $modelq->getAllQueuedTasks();
echo "   Queue cleared. Remaining tasks: " . count($remainingTasks) . "\n";

// ============================================
// 8. Working with Results (requires running worker)
// ============================================
echo "\n8. To get task results, you need to run a worker:\n";
echo "   Start a worker in another terminal:\n";
echo "   $ php examples/worker.php\n";
echo "\n   Then enqueue tasks and get results:\n";
echo "   \$task = \$modelq->enqueue('add_numbers', ['a' => 5, 'b' => 3]);\n";
echo "   \$result = \$task->getResult(\$modelq->getRedisClient(), timeout: 10);\n";

echo "\n=== Example Complete ===\n";
