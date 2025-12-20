<?php

/**
 * ModelQ PHP - Middleware Example
 *
 * This example demonstrates how to use middleware for:
 * - Logging task lifecycle events
 * - Monitoring and metrics
 * - Error handling
 * - Custom business logic
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Middleware\Middleware;
use ModelsLab\ModelQ\Task\Task;

echo "=== ModelQ PHP Middleware Example ===\n\n";

// ============================================
// Custom Middleware Implementation
// ============================================

/**
 * Logging middleware that tracks all task lifecycle events.
 */
class LoggingMiddleware extends Middleware
{
    private array $metrics = [
        'enqueued' => 0,
        'processed' => 0,
        'errors' => 0,
        'timeouts' => 0,
    ];

    public function beforeWorkerBoot(): void
    {
        $this->log('Worker', 'Starting up...');
    }

    public function afterWorkerBoot(): void
    {
        $this->log('Worker', 'Ready to process tasks');
    }

    public function beforeWorkerShutdown(): void
    {
        $this->log('Worker', 'Shutting down...');
        $this->printMetrics();
    }

    public function afterWorkerShutdown(): void
    {
        $this->log('Worker', 'Shutdown complete');
    }

    public function beforeEnqueue(?Task $task): void
    {
        if ($task) {
            $this->log('Enqueue', "Preparing task: {$task->taskName}");
        }
    }

    public function afterEnqueue(?Task $task): void
    {
        if ($task) {
            $this->log('Enqueue', "Task enqueued: {$task->taskId}");
            $this->metrics['enqueued']++;
        }
    }

    public function onError(?Task $task, ?\Throwable $error): void
    {
        $taskId = $task?->taskId ?? 'unknown';
        $errorMsg = $error?->getMessage() ?? 'Unknown error';
        $this->log('Error', "Task {$taskId} failed: {$errorMsg}");
        $this->metrics['errors']++;
    }

    public function onTimeout(?Task $task): void
    {
        $taskId = $task?->taskId ?? 'unknown';
        $this->log('Timeout', "Task {$taskId} timed out");
        $this->metrics['timeouts']++;
    }

    private function log(string $category, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$category}] {$message}\n";
    }

    public function printMetrics(): void
    {
        echo "\n--- Middleware Metrics ---\n";
        echo "  Enqueued: {$this->metrics['enqueued']}\n";
        echo "  Processed: {$this->metrics['processed']}\n";
        echo "  Errors: {$this->metrics['errors']}\n";
        echo "  Timeouts: {$this->metrics['timeouts']}\n";
        echo "--------------------------\n";
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

/**
 * Metrics middleware that tracks timing information.
 */
class MetricsMiddleware extends Middleware
{
    private array $taskStartTimes = [];

    public function beforeEnqueue(?Task $task): void
    {
        if ($task) {
            $this->taskStartTimes[$task->taskId] = microtime(true);
        }
    }

    public function afterEnqueue(?Task $task): void
    {
        if ($task && isset($this->taskStartTimes[$task->taskId])) {
            $duration = microtime(true) - $this->taskStartTimes[$task->taskId];
            echo "  [Metrics] Enqueue time: " . round($duration * 1000, 2) . "ms\n";
        }
    }
}

// ============================================
// Using Middleware
// ============================================

echo "1. Creating ModelQ with Logging Middleware\n\n";

$modelq = new ModelQ(
    host: '127.0.0.1',
    port: 6379
);

// Attach the logging middleware
$loggingMiddleware = new LoggingMiddleware();
$modelq->setMiddleware($loggingMiddleware);

// Register a simple task
$modelq->task('test_task', fn($data) => ['received' => $data]);

echo "\n2. Enqueueing Tasks (middleware hooks will be called)\n\n";

// Enqueue some tasks - middleware hooks will be triggered
$task1 = $modelq->enqueue('test_task', ['message' => 'Hello']);
$task2 = $modelq->enqueue('test_task', ['message' => 'World']);
$task3 = $modelq->enqueue('test_task', ['message' => 'ModelQ']);

echo "\n3. Middleware Metrics\n";
$loggingMiddleware->printMetrics();

// ============================================
// Middleware for Validation
// ============================================
echo "\n4. Validation Middleware Example\n";
echo "   You can use middleware to validate tasks before enqueueing:\n\n";

$validationCode = <<<'CODE'
class ValidationMiddleware extends Middleware
{
    public function beforeEnqueue(?Task $task): void
    {
        if (!$task) {
            return;
        }

        // Validate payload has required fields
        $payload = $task->payload['data'] ?? [];

        if ($task->taskName === 'process_image') {
            if (empty($payload['url'])) {
                throw new InvalidArgumentException('Image URL is required');
            }
        }

        if ($task->taskName === 'send_email') {
            if (empty($payload['to']) || empty($payload['subject'])) {
                throw new InvalidArgumentException('Email requires "to" and "subject"');
            }
        }
    }
}
CODE;

echo $validationCode . "\n";

// ============================================
// Middleware for Notifications
// ============================================
echo "\n5. Notification Middleware Example\n";
echo "   Send notifications on task completion or failure:\n\n";

$notificationCode = <<<'CODE'
class NotificationMiddleware extends Middleware
{
    private string $webhookUrl;

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function onError(?Task $task, ?\Throwable $error): void
    {
        $this->sendNotification([
            'type' => 'error',
            'task_id' => $task?->taskId,
            'task_name' => $task?->taskName,
            'error' => $error?->getMessage(),
            'timestamp' => time(),
        ]);
    }

    public function onTimeout(?Task $task): void
    {
        $this->sendNotification([
            'type' => 'timeout',
            'task_id' => $task?->taskId,
            'task_name' => $task?->taskName,
            'timestamp' => time(),
        ]);
    }

    private function sendNotification(array $data): void
    {
        // Send to webhook, Slack, email, etc.
        file_get_contents($this->webhookUrl, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($data),
            ],
        ]));
    }
}
CODE;

echo $notificationCode . "\n";

// Cleanup
$modelq->deleteQueue();

echo "\n=== Middleware Example Complete ===\n";
