<?php

/**
 * ModelQ PHP - Task History Example
 *
 * This example demonstrates how to:
 * - View past tasks and their status
 * - Get error details from failed tasks
 * - Monitor remote worker activity
 * - Get task statistics
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

echo "=== ModelQ PHP Task History Example ===\n\n";

$modelq = new ModelQ(host: '127.0.0.1', port: 6379);

// Register tasks
$modelq->task('process_image', fn($d) => null);
$modelq->task('generate_text', fn($d) => null);

// ============================================
// 1. Get Task Details (including errors)
// ============================================
echo "1. Get Task Details\n";

$task = $modelq->enqueue('process_image', ['url' => 'https://example.com/image.jpg']);
echo "   Enqueued task: {$task->taskId}\n";

$details = $modelq->getTaskDetails($task->taskId);
echo "   Task Name: {$details['task_name']}\n";
echo "   Status: {$details['status']}\n";
echo "   Created: " . date('Y-m-d H:i:s', (int)$details['created_at']) . "\n";

// If task failed, error details are available:
if (isset($details['error'])) {
    echo "   Error Type: {$details['error']['type']}\n";
    echo "   Error Message: {$details['error']['message']}\n";
    echo "   Error File: {$details['error']['file']}:{$details['error']['line']}\n";
}
echo "\n";

// ============================================
// 2. Get Task History
// ============================================
echo "2. Get Task History (most recent first)\n";

// Add a few more tasks for demonstration
$modelq->enqueue('process_image', ['url' => 'image1.jpg']);
$modelq->enqueue('generate_text', ['prompt' => 'Hello']);
$modelq->enqueue('process_image', ['url' => 'image2.jpg']);

$history = $modelq->getTaskHistory(limit: 10);
echo "   Recent tasks:\n";
foreach ($history as $task) {
    $time = isset($task['created_at']) ? date('H:i:s', (int)$task['created_at']) : 'N/A';
    echo "   - [{$time}] {$task['task_name']} ({$task['status']})\n";
}
echo "\n";

// ============================================
// 3. Filter Tasks by Status
// ============================================
echo "3. Filter Tasks by Status\n";

$queuedTasks = $modelq->getTaskHistory(limit: 10, status: 'queued');
echo "   Queued tasks: " . count($queuedTasks) . "\n";

$completedTasks = $modelq->getCompletedTasks(limit: 10);
echo "   Completed tasks: " . count($completedTasks) . "\n";

$failedTasks = $modelq->getFailedTasks(limit: 10);
echo "   Failed tasks: " . count($failedTasks) . "\n";

// Show failed task errors
if (!empty($failedTasks)) {
    echo "\n   Failed task details:\n";
    foreach ($failedTasks as $failed) {
        echo "   - Task: {$failed['task_id']}\n";
        echo "     Name: {$failed['task_name']}\n";
        if (isset($failed['error'])) {
            echo "     Error: {$failed['error']['message']}\n";
            echo "     Type: {$failed['error']['type']}\n";
        }
    }
}
echo "\n";

// ============================================
// 4. Filter Tasks by Name
// ============================================
echo "4. Filter Tasks by Name\n";

$imageTasks = $modelq->getTasksByName('process_image', limit: 10);
echo "   process_image tasks: " . count($imageTasks) . "\n";

$textTasks = $modelq->getTasksByName('generate_text', limit: 10);
echo "   generate_text tasks: " . count($textTasks) . "\n";
echo "\n";

// ============================================
// 5. Get Task Statistics
// ============================================
echo "5. Task Statistics\n";

$stats = $modelq->getTaskStats();
echo "   Total tasks: {$stats['total']}\n";
echo "\n   By Status:\n";
foreach ($stats['by_status'] as $status => $count) {
    echo "     {$status}: {$count}\n";
}

echo "\n   By Task Name:\n";
foreach ($stats['by_task_name'] as $name => $counts) {
    echo "     {$name}: {$counts['total']} total, {$counts['completed']} completed, {$counts['failed']} failed\n";
}

if (!empty($stats['failed_tasks'])) {
    echo "\n   Recent Errors:\n";
    foreach ($stats['failed_tasks'] as $failed) {
        echo "     - {$failed['task_name']}: {$failed['error']}\n";
    }
}
echo "\n";

// ============================================
// 6. Task History Count
// ============================================
echo "6. Task History Count\n";
$count = $modelq->getTaskHistoryCount();
echo "   Total tasks in history: {$count}\n\n";

// ============================================
// 7. Clear Old History
// ============================================
echo "7. Clear Old History\n";
// Clear tasks older than 7 days (default)
// $removed = $modelq->clearTaskHistory();

// Clear tasks older than 1 hour
// $removed = $modelq->clearTaskHistory(3600);

echo "   (Skipped - run manually to clean up)\n";
echo "   Usage: \$modelq->clearTaskHistory(3600); // older than 1 hour\n\n";

// ============================================
// 8. Monitoring Remote Workers
// ============================================
echo "8. Monitoring Remote Workers\n\n";

$monitoringCode = <<<'CODE'
// Dashboard/Admin Controller Example

class TaskDashboardController
{
    public function __construct(private ModelQ $modelq) {}

    public function index()
    {
        return [
            'stats' => $this->modelq->getTaskStats(),
            'recent_failed' => $this->modelq->getFailedTasks(limit: 20),
            'history_count' => $this->modelq->getTaskHistoryCount(),
            'servers' => $this->modelq->getRegisteredServerIds(),
        ];
    }

    public function taskDetails(string $taskId)
    {
        $details = $this->modelq->getTaskDetails($taskId);

        if (!$details) {
            throw new NotFoundException('Task not found');
        }

        return $details; // Includes error info if failed
    }

    public function failedTasks()
    {
        $failed = $this->modelq->getFailedTasks(limit: 100);

        return array_map(fn($task) => [
            'id' => $task['task_id'],
            'name' => $task['task_name'],
            'error' => $task['error']['message'] ?? 'Unknown',
            'error_type' => $task['error']['type'] ?? null,
            'failed_at' => $task['finished_at'],
        ], $failed);
    }
}
CODE;

echo $code = $monitoringCode . "\n";

// Cleanup for demo
$modelq->deleteQueue();
$modelq->clearTaskHistory(0); // Clear all

echo "\n=== Task History Example Complete ===\n";
