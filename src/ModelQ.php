<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ;

use ModelsLab\ModelQ\Exception\RetryTaskException;
use ModelsLab\ModelQ\Exception\TaskProcessingException;
use ModelsLab\ModelQ\Exception\TaskTimeoutException;
use ModelsLab\ModelQ\Middleware\Middleware;
use ModelsLab\ModelQ\Task\Task;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use Throwable;

/**
 * ModelQ - A lightweight PHP task queue library.
 *
 * Alternative to traditional queue systems, optimized for ML inference workloads.
 * Uses phpredis extension for fast Redis operations.
 */
class ModelQ
{
    public const HEARTBEAT_INTERVAL = 30;
    public const PRUNE_TIMEOUT = 300;
    public const PRUNE_CHECK_INTERVAL = 60;
    public const TASK_RESULT_RETENTION = 86400;
    public const TASK_HISTORY_RETENTION = 86400;  // 24 hours (configurable)
    public const TASK_TTL = 86400;                 // 24 hours TTL for all tasks
    public const DEFAULT_STREAM_TIMEOUT = 300;     // 5 minutes default stream timeout

    private Redis $redis;
    private string $serverId;
    private array $allowedTasks = [];
    private array $taskHandlers = [];
    private array $taskOptions = [];
    private ?Middleware $middleware = null;
    private ?string $webhookUrl = null;
    private ?int $requeueThreshold = null;
    private int $delaySeconds;
    private int $taskHistoryRetention;
    private int $taskTtl;
    private LoggerInterface $logger;
    private bool $running = false;

    public function __construct(
        ?Redis $redisClient = null,
        string $host = '127.0.0.1',
        int $port = 6379,
        int $db = 0,
        ?string $password = null,
        ?string $serverId = null,
        ?string $webhookUrl = null,
        ?int $requeueThreshold = null,
        int $delaySeconds = 30,
        ?int $taskHistoryRetention = null,
        ?int $taskTtl = null,
        ?LoggerInterface $logger = null
    ) {
        if ($redisClient) {
            $this->redis = $redisClient;
        } else {
            $this->redis = new Redis();
            $this->redis->connect($host, $port);
            if ($password) {
                $this->redis->auth($password);
            }
            $this->redis->select($db);
        }

        $this->serverId = $serverId ?? gethostname();
        $this->webhookUrl = $webhookUrl;
        $this->requeueThreshold = $requeueThreshold;
        $this->delaySeconds = $delaySeconds;
        $this->taskHistoryRetention = $taskHistoryRetention ?? self::TASK_HISTORY_RETENTION;
        $this->taskTtl = $taskTtl ?? self::TASK_TTL;
        $this->logger = $logger ?? new NullLogger();

        $this->registerServer();
    }

    /**
     * Get the Redis client instance.
     */
    public function getRedisClient(): Redis
    {
        return $this->redis;
    }

    /**
     * Get the list of allowed task names.
     *
     * @return string[]
     */
    public function getAllowedTasks(): array
    {
        return array_keys($this->allowedTasks);
    }

    /**
     * Set a middleware instance for lifecycle hooks.
     */
    public function setMiddleware(Middleware $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * Register this server in Redis.
     */
    public function registerServer(): void
    {
        $serverData = [
            'allowed_tasks' => array_keys($this->allowedTasks),
            'status' => 'idle',
            'last_heartbeat' => microtime(true),
        ];
        $this->redis->hSet('servers', $this->serverId, json_encode($serverData));
    }

    /**
     * Register a task handler.
     *
     * @param string $taskName The name of the task
     * @param callable $handler The task handler function
     * @param array $options Task options (timeout, stream, retries)
     */
    public function task(string $taskName, callable $handler, array $options = []): self
    {
        $this->allowedTasks[$taskName] = true;
        $this->taskHandlers[$taskName] = $handler;
        $this->taskOptions[$taskName] = array_merge([
            'timeout' => null,
            'stream' => false,
            'retries' => 0,
        ], $options);

        $this->registerServer();

        return $this;
    }

    /**
     * Enqueue a task for processing.
     *
     * @param string $taskName The name of the task to run
     * @param array $data The data to pass to the task
     * @param string|null $taskId Optional custom task ID (uses UUID if not provided)
     * @param array|null $additionalParams Optional additional parameters to include in task response
     * @return Task The queued task instance
     */
    public function enqueue(string $taskName, array $data = [], ?string $taskId = null, ?array $additionalParams = null): Task
    {
        $options = $this->taskOptions[$taskName] ?? [];

        $payload = [
            'data' => $data,
            'timeout' => $options['timeout'] ?? null,
            'stream' => $options['stream'] ?? false,
            'retries' => $options['retries'] ?? 0,
        ];

        $task = new Task(
            taskName: $taskName,
            payload: $payload,
            taskId: $taskId,
            additionalParams: $additionalParams
        );

        if ($options['stream'] ?? false) {
            $task->stream = true;
        }

        $taskDict = $task->toArray();
        $now = microtime(true);
        $taskDict['created_at'] = $now;
        $taskDict['queued_at'] = $now;

        $this->enqueueTask($taskDict, $payload);
        $this->redis->setex("task:{$task->taskId}", 86400, json_encode($taskDict));

        // Add to task history
        $this->addToTaskHistory($task->taskId, $taskDict);

        return $task;
    }

    /**
     * Push a task into the queue.
     */
    private function enqueueTask(array $taskData, array $payload): void
    {
        $taskData['status'] = 'queued';
        $this->checkMiddleware('before_enqueue');

        if (!isset($taskData['queued_at'])) {
            $taskData['queued_at'] = microtime(true);
        }

        $this->redis->rPush('ml_tasks', json_encode($taskData));
        $this->redis->zAdd('queued_requests', $taskData['queued_at'], $taskData['task_id']);
        $this->checkMiddleware('after_enqueue');
    }

    /**
     * Enqueue a delayed task.
     */
    public function enqueueDelayedTask(array $taskDict, int $delaySeconds): void
    {
        $runAt = microtime(true) + $delaySeconds;
        $taskJson = json_encode($taskDict);
        $this->redis->zAdd('delayed_tasks', $runAt, $taskJson);
        $this->logger->info("Delayed task {$taskDict['task_id']} by {$delaySeconds} seconds.");
    }

    /**
     * Start worker processes.
     *
     * @param int $workers Number of workers (for PHP single-threaded, this affects logging only)
     */
    public function startWorkers(int $workers = 1): void
    {
        $this->running = true;
        $this->checkMiddleware('before_worker_boot');
        $this->checkMiddleware('after_worker_boot');

        $lastHeartbeat = 0.0;
        $lastPrune = 0.0;
        $lastDelayedCheck = 0.0;

        $this->logger->info("ModelQ workers started. Registered tasks: " . implode(', ', array_keys($this->allowedTasks)));

        while ($this->running) {
            $now = microtime(true);

            // Heartbeat
            if ($now - $lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
                $this->heartbeat();
                $lastHeartbeat = $now;
            }

            // Pruning
            if ($now - $lastPrune >= self::PRUNE_CHECK_INTERVAL) {
                $this->pruneInactiveServers();
                $this->requeueStuckProcessingTasks();
                $this->pruneOldTaskResults();
                $lastPrune = $now;
            }

            // Check delayed tasks
            if ($now - $lastDelayedCheck >= 1) {
                $this->requeueDelayedTasks();
                $lastDelayedCheck = $now;
            }

            // Process tasks
            $this->updateServerStatus('idle');
            $taskData = $this->redis->blPop(['ml_tasks'], 1);

            if (!$taskData) {
                continue;
            }

            $this->updateServerStatus('busy');
            $taskJson = $taskData[1];
            $taskDict = json_decode($taskJson, true);
            $task = Task::fromArray($taskDict);

            // Mark as processing
            $added = $this->redis->sAdd('processing_tasks', $task->taskId);
            if ($added === 0) {
                $this->logger->warning("Task {$task->taskId} is already being processed. Skipping duplicate.");
                continue;
            }
            $task->status = 'processing';
            $taskDict['started_at'] = microtime(true);
            $this->redis->setex("task:{$task->taskId}", 86400, json_encode($taskDict));

            if (isset($this->allowedTasks[$task->taskName])) {
                try {
                    $this->logger->info("Started processing: {$task->taskName}");
                    $startTime = microtime(true);
                    $this->processTask($task);
                    $endTime = microtime(true);
                    $this->logger->info(sprintf(
                        "Finished %s in %.2f seconds",
                        $task->taskName,
                        $endTime - $startTime
                    ));
                } catch (TaskProcessingException $e) {
                    $this->logger->error("TaskProcessingError: " . $e->getMessage());
                    $this->handleRetry($task, $taskDict);
                } catch (Throwable $e) {
                    $this->logger->error("Unexpected error: " . $e->getMessage());
                    $this->handleRetry($task, $taskDict);
                }
            } else {
                $this->logger->warning("Cannot process task {$task->taskName}, re-queueing...");
                $this->redis->rPush('ml_tasks', $taskJson);
            }
        }

        $this->checkMiddleware('before_worker_shutdown');
        $this->checkMiddleware('after_worker_shutdown');
    }

    /**
     * Stop the worker loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Handle task retry logic.
     */
    private function handleRetry(Task $task, array $taskDict): void
    {
        $retries = $task->payload['retries'] ?? 0;
        if ($retries > 0) {
            $newTaskDict = $task->toArray();
            $newTaskDict['payload'] = $task->originalPayload;
            $newTaskDict['payload']['retries'] = $retries - 1;
            $this->enqueueDelayedTask($newTaskDict, $this->delaySeconds);
        }
    }

    /**
     * Process a task.
     */
    private function processTask(Task $task): void
    {
        try {
            if (!isset($this->allowedTasks[$task->taskName])) {
                $task->status = 'failed';
                $task->result = 'Task not allowed on this server.';
                $this->storeFinalTaskState($task, false);
                $this->logger->error("Task {$task->taskName} is not allowed on this server.");
                throw new TaskProcessingException($task->taskName, 'Task not allowed');
            }

            $handler = $this->taskHandlers[$task->taskName] ?? null;
            if (!$handler) {
                $task->status = 'failed';
                $task->result = 'Task handler not found';
                $this->storeFinalTaskState($task, false);
                $this->logger->error("Task {$task->taskName} failed - handler not found.");
                throw new TaskProcessingException($task->taskName, 'Task handler not found');
            }

            $data = $task->payload['data'] ?? [];
            $timeout = $task->payload['timeout'] ?? null;
            $stream = $task->payload['stream'] ?? false;

            $this->logger->info("Processing task: {$task->taskName} with data: " . json_encode($data));

            if ($stream) {
                // Stream results using Redis Streams
                $generator = $handler($data);
                if ($generator instanceof \Generator) {
                    foreach ($generator as $result) {
                        $task->status = 'in_progress';
                        $this->redis->xAdd(
                            "task_stream:{$task->taskId}",
                            '*',
                            ['result' => json_encode($result)]
                        );
                    }
                }
                $task->status = 'completed';
                $this->redis->expire("task_stream:{$task->taskId}", 3600);
                $this->storeFinalTaskState($task, true);
            } else {
                // Standard execution
                if ($timeout) {
                    $result = $this->runWithTimeout($handler, $timeout, $data);
                } else {
                    $result = $handler($data);
                }

                $task->result = $result;
                $task->status = 'completed';
                $this->storeFinalTaskState($task, true);
            }

            $this->logger->info("Task {$task->taskName} completed successfully.");
        } catch (RetryTaskException $e) {
            $this->logger->warning("Task {$task->taskName} requested retry: " . $e->getMessage());
            $newTaskDict = $task->toArray();
            $newTaskDict['payload'] = $task->originalPayload;
            $this->enqueueDelayedTask($newTaskDict, $this->delaySeconds);
        } catch (Throwable $e) {
            $task->status = 'failed';
            $task->result = $e->getMessage();
            $this->storeFinalTaskState($task, false, $e);

            $this->logTaskErrorToFile($task, $e);
            $this->checkMiddleware('on_error', $task, $e);
            $this->postErrorToWebhook($task, $e);

            $this->logger->error("Task {$task->taskName} failed with error: " . $e->getMessage());
            throw new TaskProcessingException($task->taskName, $e->getMessage(), 0, $e);
        } finally {
            $this->redis->sRem('processing_tasks', $task->taskId);
        }
    }

    /**
     * Run a handler with a timeout.
     */
    private function runWithTimeout(callable $handler, int $timeout, array $data): mixed
    {
        $startTime = microtime(true);

        // Set alarm if pcntl is available
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm($timeout);
            try {
                $result = $handler($data);
                pcntl_alarm(0);
                return $result;
            } catch (\Throwable $e) {
                pcntl_alarm(0);
                throw $e;
            }
        }

        // Fallback: just run and check time after
        $result = $handler($data);
        $elapsed = microtime(true) - $startTime;

        if ($elapsed > $timeout) {
            throw new TaskTimeoutException(
                'timeout',
                "Task exceeded timeout of {$timeout} seconds"
            );
        }

        return $result;
    }

    /**
     * Store the final state of a task in Redis.
     */
    private function storeFinalTaskState(Task $task, bool $success, ?Throwable $error = null): void
    {
        $taskDict = $task->toArray();
        $taskDict['finished_at'] = microtime(true);

        // Add error details if failed
        if (!$success && $error) {
            $taskDict['error'] = [
                'message' => $error->getMessage(),
                'type' => get_class($error),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
            ];
        }

        $this->redis->setex("task_result:{$task->taskId}", 3600, json_encode($taskDict));
        $this->redis->setex("task:{$task->taskId}", 86400, json_encode($taskDict));

        // Update task history
        $this->updateTaskHistory($task->taskId, $taskDict);
    }

    /**
     * Update server heartbeat.
     */
    private function heartbeat(): void
    {
        $rawData = $this->redis->hGet('servers', $this->serverId);
        if (!$rawData) {
            $this->registerServer();
            return;
        }

        $data = json_decode($rawData, true);
        $data['last_heartbeat'] = microtime(true);
        $this->redis->hSet('servers', $this->serverId, json_encode($data));
    }

    /**
     * Update server status in Redis.
     */
    private function updateServerStatus(string $status): void
    {
        $rawData = $this->redis->hGet('servers', $this->serverId);
        if (!$rawData) {
            $this->registerServer();
            return;
        }

        $serverData = json_decode($rawData, true);
        $serverData['status'] = $status;
        $serverData['last_heartbeat'] = microtime(true);
        $this->redis->hSet('servers', $this->serverId, json_encode($serverData));
    }

    /**
     * Check and execute middleware.
     */
    private function checkMiddleware(string $event, ?Task $task = null, ?Throwable $error = null): void
    {
        if ($this->middleware) {
            $this->middleware->execute($event, $task, $error);
        }
    }

    /**
     * Get registered server IDs.
     *
     * @return string[]
     */
    public function getRegisteredServerIds(): array
    {
        $keys = $this->redis->hKeys('servers');
        return $keys ?: [];
    }

    /**
     * Get all registered workers with their system info.
     *
     * Returns worker details including:
     * - worker_id: Unique worker identifier
     * - status: Current status (idle, busy)
     * - allowed_tasks: List of tasks this worker handles
     * - last_heartbeat: Unix timestamp of last heartbeat
     * - system_info: CPU, RAM, GPU information (if provided by worker)
     *
     * @return array<string, array> Map of worker_id => worker data
     */
    public function getWorkers(): array
    {
        $workers = [];
        $allServers = $this->redis->hGetAll('servers');

        foreach ($allServers as $serverId => $dataJson) {
            try {
                $data = json_decode($dataJson, true);
                $workers[$serverId] = [
                    'worker_id' => $serverId,
                    'status' => $data['status'] ?? 'unknown',
                    'allowed_tasks' => $data['allowed_tasks'] ?? [],
                    'last_heartbeat' => array_key_exists('last_heartbeat', $data) ? $data['last_heartbeat'] : null,
                    'system_info' => array_key_exists('system_info', $data) ? $data['system_info'] : null,
                    'hostname' => array_key_exists('hostname', $data) ? $data['hostname'] : null,
                    'os' => array_key_exists('os', $data) ? $data['os'] : null,
                    'python_version' => array_key_exists('python_version', $data) ? $data['python_version'] : null,
                    'php_version' => array_key_exists('php_version', $data) ? $data['php_version'] : null,
                ];
            } catch (Throwable $e) {
                $this->logger->warning("Could not parse worker data for {$serverId}: " . $e->getMessage());
            }
        }

        return $workers;
    }

    /**
     * Get a specific worker's info by ID.
     *
     * @param string $workerId The worker ID to look up
     * @return array|null Worker data or null if not found
     */
    public function getWorker(string $workerId): ?array
    {
        $dataJson = $this->redis->hGet('servers', $workerId);
        if (!$dataJson) {
            return null;
        }

        try {
            $data = json_decode($dataJson, true);
            return [
                'worker_id' => $workerId,
                'status' => $data['status'] ?? 'unknown',
                'allowed_tasks' => $data['allowed_tasks'] ?? [],
                'last_heartbeat' => array_key_exists('last_heartbeat', $data) ? $data['last_heartbeat'] : null,
                'system_info' => array_key_exists('system_info', $data) ? $data['system_info'] : null,
                'hostname' => array_key_exists('hostname', $data) ? $data['hostname'] : null,
                'os' => array_key_exists('os', $data) ? $data['os'] : null,
                'python_version' => array_key_exists('python_version', $data) ? $data['python_version'] : null,
                'php_version' => array_key_exists('php_version', $data) ? $data['php_version'] : null,
            ];
        } catch (Throwable $e) {
            $this->logger->warning("Could not parse worker data for {$workerId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all queued tasks.
     */
    public function getAllQueuedTasks(): array
    {
        $queuedTasks = [];
        $tasksInList = $this->redis->lRange('ml_tasks', 0, -1);

        foreach ($tasksInList as $taskJson) {
            try {
                $taskDict = json_decode($taskJson, true);
                if (($taskDict['status'] ?? '') === 'queued') {
                    $queuedTasks[] = $taskDict;
                }
            } catch (Throwable $e) {
                $this->logger->error("Error deserializing task from ml_tasks: " . $e->getMessage());
            }
        }

        return $queuedTasks;
    }

    /**
     * Get task status by ID.
     */
    public function getTaskStatus(string $taskId): ?string
    {
        $taskData = $this->redis->get("task:{$taskId}");
        if ($taskData) {
            $data = json_decode($taskData, true);
            return $data['status'] ?? null;
        }
        return null;
    }

    // ========================================
    // Task History Methods
    // ========================================

    /**
     * Add a task to history.
     */
    private function addToTaskHistory(string $taskId, array $taskData): void
    {
        $score = $taskData['created_at'] ?? microtime(true);
        $this->redis->zAdd('task_history', $score, $taskId);
        $this->redis->setex("task_history:{$taskId}", self::TASK_HISTORY_RETENTION, json_encode($taskData));
    }

    /**
     * Update task in history.
     */
    private function updateTaskHistory(string $taskId, array $taskData): void
    {
        $this->redis->setex("task_history:{$taskId}", self::TASK_HISTORY_RETENTION, json_encode($taskData));
    }

    /**
     * Get full task details including error information.
     *
     * @return array|null Task data with error details if failed
     */
    public function getTaskDetails(string $taskId): ?array
    {
        // First try task_history (longer retention)
        $taskData = $this->redis->get("task_history:{$taskId}");
        if (!$taskData) {
            // Fallback to task:{id}
            $taskData = $this->redis->get("task:{$taskId}");
        }

        if ($taskData) {
            return json_decode($taskData, true);
        }

        return null;
    }

    /**
     * Get task history with optional filters.
     *
     * @param int $limit Maximum number of tasks to return
     * @param int $offset Number of tasks to skip
     * @param string|null $status Filter by status (queued, processing, completed, failed)
     * @param string|null $taskName Filter by task name
     * @return array List of tasks with their details
     */
    public function getTaskHistory(
        int $limit = 50,
        int $offset = 0,
        ?string $status = null,
        ?string $taskName = null
    ): array {
        // Get task IDs from sorted set (newest first)
        $taskIds = $this->redis->zRevRange('task_history', $offset, $offset + $limit - 1);

        $tasks = [];
        foreach ($taskIds as $taskId) {
            $taskData = $this->redis->get("task_history:{$taskId}");
            if ($taskData) {
                $task = json_decode($taskData, true);

                // Apply filters
                if ($status !== null && ($task['status'] ?? '') !== $status) {
                    continue;
                }
                if ($taskName !== null && ($task['task_name'] ?? '') !== $taskName) {
                    continue;
                }

                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * Get failed tasks with error details.
     *
     * @param int $limit Maximum number of tasks to return
     * @return array List of failed tasks with error information
     */
    public function getFailedTasks(int $limit = 50): array
    {
        return $this->getTaskHistory(limit: $limit, status: 'failed');
    }

    /**
     * Get completed tasks.
     *
     * @param int $limit Maximum number of tasks to return
     * @return array List of completed tasks
     */
    public function getCompletedTasks(int $limit = 50): array
    {
        return $this->getTaskHistory(limit: $limit, status: 'completed');
    }

    /**
     * Get tasks by task name.
     *
     * @param string $taskName The task name to filter by
     * @param int $limit Maximum number of tasks to return
     * @return array List of tasks matching the name
     */
    public function getTasksByName(string $taskName, int $limit = 50): array
    {
        return $this->getTaskHistory(limit: $limit, taskName: $taskName);
    }

    /**
     * Get task statistics.
     *
     * @return array Statistics about tasks in history
     */
    public function getTaskStats(): array
    {
        $taskIds = $this->redis->zRange('task_history', 0, -1);

        $stats = [
            'total' => count($taskIds),
            'by_status' => [
                'queued' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
            ],
            'by_task_name' => [],
            'failed_tasks' => [],
        ];

        foreach ($taskIds as $taskId) {
            $taskData = $this->redis->get("task_history:{$taskId}");
            if ($taskData) {
                $task = json_decode($taskData, true);
                $status = $task['status'] ?? 'unknown';
                $taskName = $task['task_name'] ?? 'unknown';

                // Count by status
                if (isset($stats['by_status'][$status])) {
                    $stats['by_status'][$status]++;
                }

                // Count by task name
                if (!isset($stats['by_task_name'][$taskName])) {
                    $stats['by_task_name'][$taskName] = ['total' => 0, 'completed' => 0, 'failed' => 0];
                }
                $stats['by_task_name'][$taskName]['total']++;
                if ($status === 'completed') {
                    $stats['by_task_name'][$taskName]['completed']++;
                } elseif ($status === 'failed') {
                    $stats['by_task_name'][$taskName]['failed']++;
                    // Add to failed tasks list (limit to 10 most recent)
                    if (count($stats['failed_tasks']) < 10) {
                        $stats['failed_tasks'][] = [
                            'task_id' => $task['task_id'],
                            'task_name' => $taskName,
                            'error' => $task['error']['message'] ?? $task['result'] ?? 'Unknown error',
                            'finished_at' => $task['finished_at'] ?? null,
                        ];
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Clear old task history.
     *
     * @param int $olderThanSeconds Remove tasks older than this many seconds
     * @return int Number of tasks removed
     */
    public function clearTaskHistory(int $olderThanSeconds = 604800): int
    {
        $cutoff = microtime(true) - $olderThanSeconds;

        // Get old task IDs
        $oldTaskIds = $this->redis->zRangeByScore('task_history', '-inf', (string) $cutoff);

        $removed = 0;
        foreach ($oldTaskIds as $taskId) {
            $this->redis->zRem('task_history', $taskId);
            $this->redis->del("task_history:{$taskId}");
            $removed++;
        }

        return $removed;
    }

    /**
     * Get task count in history.
     */
    public function getTaskHistoryCount(): int
    {
        return $this->redis->zCard('task_history') ?: 0;
    }

    /**
     * Delete the entire queue.
     */
    public function deleteQueue(): void
    {
        $this->redis->lTrim('ml_tasks', 1, 0);
    }

    /**
     * Remove a task from the queue.
     */
    public function removeTaskFromQueue(string $taskId): bool
    {
        $tasks = $this->redis->lRange('ml_tasks', 0, -1);
        $removed = false;

        foreach ($tasks as $taskJson) {
            try {
                $taskDict = json_decode($taskJson, true);
                if (($taskDict['task_id'] ?? '') === $taskId) {
                    $this->redis->lRem('ml_tasks', $taskJson, 1);
                    $this->redis->zRem('queued_requests', $taskId);
                    $removed = true;
                    $this->logger->info("Removed task {$taskId} from queue.");
                    break;
                }
            } catch (Throwable $e) {
                $this->logger->error("Failed to process task while trying to remove: " . $e->getMessage());
            }
        }

        return $removed;
    }

    /**
     * Prune inactive servers.
     */
    private function pruneInactiveServers(?int $timeoutSeconds = null): void
    {
        $timeoutSeconds = $timeoutSeconds ?? self::PRUNE_TIMEOUT;
        $allServers = $this->redis->hGetAll('servers');
        $now = microtime(true);
        $removedCount = 0;

        foreach ($allServers as $serverId => $dataJson) {
            try {
                $data = json_decode($dataJson, true);
                $lastHeartbeat = $data['last_heartbeat'] ?? 0;
                if (($now - $lastHeartbeat) > $timeoutSeconds) {
                    $this->redis->hDel('servers', $serverId);
                    $removedCount++;
                    $this->logger->info("[Prune] Removed stale server: {$serverId}");
                }
            } catch (Throwable $e) {
                $this->logger->warning("[Prune] Could not parse server data for {$serverId}: " . $e->getMessage());
            }
        }

        if ($removedCount > 0) {
            $this->logger->info("[Prune] Total {$removedCount} inactive servers pruned.");
        }
    }

    /**
     * Requeue stuck processing tasks.
     */
    private function requeueStuckProcessingTasks(?float $threshold = null): void
    {
        $threshold = $threshold ?? $this->requeueThreshold ?? 180.0;
        $processingTaskIds = $this->redis->sMembers('processing_tasks');
        $now = microtime(true);

        foreach ($processingTaskIds as $taskId) {
            $taskData = $this->redis->get("task:{$taskId}");
            if (!$taskData) {
                $this->redis->sRem('processing_tasks', $taskId);
                $this->logger->warning("No record found for in-progress task {$taskId}. Removing from 'processing_tasks'.");
                continue;
            }

            $taskDict = json_decode($taskData, true);
            $startedAt = $taskDict['started_at'] ?? 0;
            if ($startedAt && ($now - $startedAt) > $threshold) {
                $this->logger->info(sprintf(
                    "Re-queuing stuck task %s which has been 'processing' for %.2f seconds.",
                    $taskId,
                    $now - $startedAt
                ));

                $taskDict['status'] = 'queued';
                $taskDict['queued_at'] = $now;

                $this->redis->setex("task:{$taskId}", 86400, json_encode($taskDict));
                $this->redis->rPush('ml_tasks', json_encode($taskDict));
                $this->redis->sRem('processing_tasks', $taskId);
            }
        }
    }

    /**
     * Prune old task results.
     */
    private function pruneOldTaskResults(?int $olderThanSeconds = null): void
    {
        $olderThanSeconds = $olderThanSeconds ?? self::TASK_RESULT_RETENTION;
        $now = microtime(true);
        $keysDeleted = 0;

        $iterator = null;
        while ($keys = $this->redis->scan($iterator, 'task_result:*', 100)) {
            foreach ($keys as $key) {
                try {
                    $taskJson = $this->redis->get($key);
                    if (!$taskJson) {
                        continue;
                    }

                    $taskData = json_decode($taskJson, true);
                    $timestamp = $taskData['finished_at'] ?? $taskData['started_at'] ?? null;

                    if ($timestamp && ($now - $timestamp) > $olderThanSeconds) {
                        $this->redis->del($key);
                        $taskId = str_replace('task_result:', '', $key);
                        $this->redis->del("task:{$taskId}");
                        $keysDeleted++;
                        $this->logger->info("Deleted old keys: {$key} and task:{$taskId}");
                    }
                } catch (Throwable $e) {
                    $this->logger->error("Error processing key {$key}: " . $e->getMessage());
                }
            }

            if ($iterator === 0) {
                break;
            }
        }

        if ($keysDeleted > 0) {
            $this->logger->info("Pruned {$keysDeleted} task(s) older than {$olderThanSeconds} seconds.");
        }
    }

    /**
     * Requeue delayed tasks that are ready to run.
     */
    private function requeueDelayedTasks(): void
    {
        $now = microtime(true);
        $readyTasks = $this->redis->zRangeByScore('delayed_tasks', '-inf', (string) $now);

        foreach ($readyTasks as $taskJson) {
            $this->redis->zRem('delayed_tasks', $taskJson);
            $this->redis->lPush('ml_tasks', $taskJson);
        }
    }

    /**
     * Get processing tasks.
     */
    public function getProcessingTasks(): array
    {
        $results = [];
        $rawIds = $this->redis->sMembers('processing_tasks');

        if (empty($rawIds)) {
            return $results;
        }

        foreach ($rawIds as $taskId) {
            $taskJson = $this->redis->get("task:{$taskId}");
            if (!$taskJson) {
                $this->redis->sRem('processing_tasks', $taskId);
                $this->logger->warning("Stale processing entry removed (no record): {$taskId}");
                continue;
            }

            try {
                $taskDict = json_decode($taskJson, true);
            } catch (Throwable $e) {
                $this->logger->error("Failed to parse task record for {$taskId}: " . $e->getMessage());
                $this->redis->sRem('processing_tasks', $taskId);
                continue;
            }

            $status = $taskDict['status'] ?? null;
            if ($status === 'processing') {
                $results[] = $taskDict;
            } else {
                $this->redis->sRem('processing_tasks', $taskId);
            }
        }

        return $results;
    }

    /**
     * Log task error to file.
     */
    private function logTaskErrorToFile(Task $task, Throwable $exc, string $filePath = 'modelq_errors.log'): void
    {
        $logData = [
            'task_id' => $task->taskId,
            'task_name' => $task->taskName,
            'payload' => $task->payload,
            'error_message' => $exc->getMessage(),
            'traceback' => $exc->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $content = "----\n" . json_encode($logData, JSON_PRETTY_PRINT) . "\n----\n";
        file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);
    }

    /**
     * Post error to webhook.
     */
    private function postErrorToWebhook(Task $task, Throwable $exc): void
    {
        if (!$this->webhookUrl) {
            return;
        }

        $payloadStr = json_encode($task->payload, JSON_PRETTY_PRINT);
        $traceback = $exc->getTraceAsString();

        $content = <<<EOT
**Task Name**: {$task->taskName}
**Task ID**: {$task->taskId}
**Payload**:
```json
{$payloadStr}
```
**Error Message**: {$exc->getMessage()}
**Traceback**:
```
{$traceback}
```
EOT;

        // Non-blocking HTTP POST
        $payload = json_encode(['content' => $content]);

        $ch = curl_init($this->webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 400) {
            $this->logger->error("Failed to POST error to webhook. Status code: {$httpCode}");
        }

        curl_close($ch);
    }

    // ============================================
    // Task Cancellation Methods
    // ============================================

    /**
     * Cancel a task. Works for queued or processing tasks.
     * - If queued: removes from queue and marks as cancelled
     * - If processing: marks as cancelled (worker will check and stop)
     *
     * @return bool True if task was found and cancelled
     */
    public function cancelTask(string $taskId): bool
    {
        // Set cancellation flag
        $this->redis->setex("task:{$taskId}:cancelled", $this->taskTtl, '1');

        // Try to remove from queue
        $removedFromQueue = $this->removeTaskFromQueue($taskId);

        // Update task status
        $taskJson = $this->redis->get("task:{$taskId}");
        if ($taskJson) {
            $taskDict = json_decode($taskJson, true);
            $taskDict['status'] = 'cancelled';
            $taskDict['finished_at'] = microtime(true);
            $this->redis->setex("task:{$taskId}", $this->taskTtl, json_encode($taskDict));
            $this->updateTaskHistory($taskId, $taskDict);
            $this->logger->info("Task {$taskId} cancelled.");
            return true;
        }

        return $removedFromQueue;
    }

    /**
     * Check if a task has been cancelled.
     * Workers should call this periodically during long-running tasks.
     */
    public function isTaskCancelled(string $taskId): bool
    {
        return $this->redis->exists("task:{$taskId}:cancelled") > 0;
    }

    /**
     * Get tasks that have been cancelled.
     */
    public function getCancelledTasks(int $limit = 100): array
    {
        return $this->getTaskHistory($limit, 0, 'cancelled');
    }

    // ============================================
    // Progress Tracking Methods
    // ============================================

    /**
     * Report progress for a long-running task.
     *
     * @param string $taskId The task ID
     * @param float $progress Progress value between 0.0 and 1.0 (0% to 100%)
     * @param string|null $message Optional progress message
     */
    public function reportProgress(string $taskId, float $progress, ?string $message = null): void
    {
        $progressData = [
            'progress' => min(max($progress, 0.0), 1.0), // Clamp to 0-1
            'message' => $message,
            'updated_at' => microtime(true),
        ];

        $this->redis->setex(
            "task:{$taskId}:progress",
            $this->taskTtl,
            json_encode($progressData)
        );
    }

    /**
     * Get the current progress of a task.
     *
     * @return array|null Dict with progress, message, updated_at or null if no progress
     */
    public function getTaskProgress(string $taskId): ?array
    {
        $progressJson = $this->redis->get("task:{$taskId}:progress");
        if ($progressJson) {
            return json_decode($progressJson, true);
        }
        return null;
    }

    // ============================================
    // Task TTL Validation
    // ============================================

    /**
     * Remove tasks that have exceeded their TTL from the queue.
     *
     * @return int Number of expired tasks removed
     */
    public function cleanupExpiredTasks(): int
    {
        $removed = 0;
        $cutoff = microtime(true) - $this->taskTtl;

        // Check queued tasks
        $tasksInQueue = $this->redis->lRange('ml_tasks', 0, -1);
        foreach ($tasksInQueue as $taskJson) {
            try {
                $taskDict = json_decode($taskJson, true);
                $createdAt = $taskDict['created_at'] ?? 0;
                if ($createdAt && $createdAt < $cutoff) {
                    $this->redis->lRem('ml_tasks', $taskJson, 1);
                    $taskId = $taskDict['task_id'] ?? null;
                    if ($taskId) {
                        $taskDict['status'] = 'expired';
                        $taskDict['finished_at'] = microtime(true);
                        $this->updateTaskHistory($taskId, $taskDict);
                        $this->logger->info("Removed expired task {$taskId} from queue.");
                    }
                    $removed++;
                }
            } catch (Throwable $e) {
                $this->logger->error("Error checking task expiry: {$e->getMessage()}");
            }
        }

        return $removed;
    }

    /**
     * Get the task TTL setting.
     */
    public function getTaskTtl(): int
    {
        return $this->taskTtl;
    }

    /**
     * Get the task history retention setting.
     */
    public function getTaskHistoryRetention(): int
    {
        return $this->taskHistoryRetention;
    }
}
