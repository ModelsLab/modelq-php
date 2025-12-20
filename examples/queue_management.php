<?php

/**
 * ModelQ PHP - Queue Management Example
 *
 * This example demonstrates queue management operations:
 * - Listing queued tasks
 * - Getting task status
 * - Removing tasks
 * - Clearing the queue
 * - Delayed tasks
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

echo "=== ModelQ PHP Queue Management Example ===\n\n";

// Initialize ModelQ
$modelq = new ModelQ(
    host: '127.0.0.1',
    port: 6379
);

// Register a test task
$modelq->task('test_task', fn($data) => $data);
$modelq->task('important_task', fn($data) => $data);
$modelq->task('background_task', fn($data) => $data);

// Clear any existing tasks
$modelq->deleteQueue();
echo "Queue cleared.\n\n";

// ============================================
// 1. Enqueue Multiple Tasks
// ============================================
echo "1. Enqueueing Multiple Tasks\n";

$tasks = [];
$tasks[] = $modelq->enqueue('test_task', ['id' => 1, 'data' => 'First task']);
$tasks[] = $modelq->enqueue('test_task', ['id' => 2, 'data' => 'Second task']);
$tasks[] = $modelq->enqueue('important_task', ['id' => 3, 'priority' => 'high']);
$tasks[] = $modelq->enqueue('background_task', ['id' => 4, 'type' => 'cleanup']);
$tasks[] = $modelq->enqueue('test_task', ['id' => 5, 'data' => 'Fifth task']);

echo "   Enqueued " . count($tasks) . " tasks\n\n";

// ============================================
// 2. List All Queued Tasks
// ============================================
echo "2. Listing All Queued Tasks\n";

$queuedTasks = $modelq->getAllQueuedTasks();
echo "   Total tasks in queue: " . count($queuedTasks) . "\n\n";

echo "   +--------------------------------------+------------------+\n";
echo "   | Task ID                              | Task Name        |\n";
echo "   +--------------------------------------+------------------+\n";

foreach ($queuedTasks as $task) {
    $taskId = str_pad($task['task_id'], 36);
    $taskName = str_pad($task['task_name'], 16);
    echo "   | {$taskId} | {$taskName} |\n";
}
echo "   +--------------------------------------+------------------+\n\n";

// ============================================
// 3. Get Task Status
// ============================================
echo "3. Getting Task Status\n";

foreach ($tasks as $index => $task) {
    $status = $modelq->getTaskStatus($task->taskId);
    echo "   Task " . ($index + 1) . ": {$status}\n";
}
echo "\n";

// ============================================
// 4. Remove a Specific Task
// ============================================
echo "4. Removing a Specific Task\n";

$taskToRemove = $tasks[2]; // Remove the third task (important_task)
echo "   Removing task: {$taskToRemove->taskId}\n";

$removed = $modelq->removeTaskFromQueue($taskToRemove->taskId);
echo "   Removed: " . ($removed ? 'Yes' : 'No') . "\n";

// Verify removal
$queuedTasks = $modelq->getAllQueuedTasks();
echo "   Remaining tasks: " . count($queuedTasks) . "\n\n";

// ============================================
// 5. Filter Tasks by Name
// ============================================
echo "5. Filtering Tasks by Name\n";

$testTasks = array_filter($queuedTasks, fn($t) => $t['task_name'] === 'test_task');
$backgroundTasks = array_filter($queuedTasks, fn($t) => $t['task_name'] === 'background_task');

echo "   test_task count: " . count($testTasks) . "\n";
echo "   background_task count: " . count($backgroundTasks) . "\n\n";

// ============================================
// 6. Get Task Details
// ============================================
echo "6. Getting Task Details\n";

$redis = $modelq->getRedisClient();
$taskId = $tasks[0]->taskId;
$taskJson = $redis->get("task:{$taskId}");

if ($taskJson) {
    $taskData = json_decode($taskJson, true);
    echo "   Task ID: {$taskData['task_id']}\n";
    echo "   Task Name: {$taskData['task_name']}\n";
    echo "   Status: {$taskData['status']}\n";
    echo "   Payload: " . json_encode($taskData['payload']) . "\n\n";
}

// ============================================
// 7. Delayed Tasks
// ============================================
echo "7. Delayed Tasks\n";

// Enqueue a delayed task (will run after 60 seconds)
$delayedTaskData = [
    'task_id' => 'delayed-' . uniqid(),
    'task_name' => 'test_task',
    'payload' => ['message' => 'This task was delayed'],
    'status' => 'queued',
];

$modelq->enqueueDelayedTask($delayedTaskData, delaySeconds: 60);
echo "   Enqueued delayed task: {$delayedTaskData['task_id']}\n";
echo "   Will execute in 60 seconds\n";

// Check delayed tasks
$delayedTasks = $redis->zRange('delayed_tasks', 0, -1, true);
echo "   Total delayed tasks: " . count($delayedTasks) . "\n\n";

// ============================================
// 8. Processing Tasks (Simulated)
// ============================================
echo "8. Processing Tasks Status\n";

// Manually add a task to processing set for demonstration
$processingTaskId = 'processing-' . uniqid();
$redis->sAdd('processing_tasks', $processingTaskId);
$redis->set("task:{$processingTaskId}", json_encode([
    'task_id' => $processingTaskId,
    'task_name' => 'demo_task',
    'status' => 'processing',
    'payload' => [],
]));

$processingTasks = $modelq->getProcessingTasks();
echo "   Currently processing: " . count($processingTasks) . " task(s)\n";

foreach ($processingTasks as $task) {
    echo "   - {$task['task_id']} ({$task['task_name']})\n";
}

// Cleanup demo processing task
$redis->sRem('processing_tasks', $processingTaskId);
$redis->del("task:{$processingTaskId}");
echo "\n";

// ============================================
// 9. Queue Statistics
// ============================================
echo "9. Queue Statistics\n";

$servers = $modelq->getRegisteredServerIds();
$queuedCount = count($modelq->getAllQueuedTasks());
$processingCount = count($modelq->getProcessingTasks());
$delayedCount = $redis->zCard('delayed_tasks');

echo "   +----------------------+-------+\n";
echo "   | Metric               | Value |\n";
echo "   +----------------------+-------+\n";
echo "   | Registered Servers   | " . str_pad((string)count($servers), 5) . " |\n";
echo "   | Queued Tasks         | " . str_pad((string)$queuedCount, 5) . " |\n";
echo "   | Processing Tasks     | " . str_pad((string)$processingCount, 5) . " |\n";
echo "   | Delayed Tasks        | " . str_pad((string)$delayedCount, 5) . " |\n";
echo "   +----------------------+-------+\n\n";

// ============================================
// 10. Clear the Queue
// ============================================
echo "10. Clearing the Queue\n";

$modelq->deleteQueue();
$remaining = $modelq->getAllQueuedTasks();
echo "    Queue cleared. Remaining tasks: " . count($remaining) . "\n";

// Also clean up delayed tasks
$redis->del('delayed_tasks');
echo "    Delayed tasks cleared.\n";

echo "\n=== Queue Management Example Complete ===\n";
