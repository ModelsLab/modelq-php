<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Task;

use Generator;
use ModelsLab\ModelQ\Exception\TaskProcessingException;
use ModelsLab\ModelQ\Exception\TaskTimeoutException;
use Redis;

/**
 * Represents a task in the ModelQ queue system.
 */
class Task
{
    public string $taskId;
    public string $taskName;
    public array $payload;
    public array $originalPayload;
    public string $status = 'queued';
    public mixed $result = null;
    public ?float $createdAt = null;
    public ?float $queuedAt = null;
    public ?float $startedAt = null;
    public ?float $finishedAt = null;
    public int $timeout;
    public bool $stream = false;
    public string $combinedResult = '';

    public function __construct(
        string $taskName,
        array $payload,
        int $timeout = 15,
        ?string $taskId = null
    ) {
        $this->taskId = $taskId ?? $this->generateUuid();
        $this->taskName = $taskName;
        $this->payload = $payload;
        $this->originalPayload = $payload;
        $this->createdAt = microtime(true);
        $this->timeout = $timeout;
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Convert task to array representation.
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'task_name' => $this->taskName,
            'payload' => $this->payload,
            'status' => $this->status,
            'result' => $this->result,
            'created_at' => $this->createdAt,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'stream' => $this->stream,
        ];
    }

    /**
     * Create a Task instance from array data.
     */
    public static function fromArray(array $data): self
    {
        $task = new self(
            taskName: $data['task_name'],
            payload: $data['payload']
        );

        $task->taskId = $data['task_id'];
        $task->status = $data['status'];
        $task->result = $data['result'] ?? null;
        $task->createdAt = $data['created_at'] ?? null;
        $task->queuedAt = $data['queued_at'] ?? null;
        $task->startedAt = $data['started_at'] ?? null;
        $task->finishedAt = $data['finished_at'] ?? null;
        $task->stream = $data['stream'] ?? false;
        $task->originalPayload = $data['payload'];

        return $task;
    }

    /**
     * Generator to yield results from a streaming task.
     *
     * @param Redis $redis Redis client instance
     * @param int $timeout Maximum time to wait for stream in seconds (default 300s/5min)
     * @return Generator<mixed>
     * @throws TaskTimeoutException If stream times out
     * @throws TaskProcessingException If task failed or was cancelled
     */
    public function getStream(Redis $redis, int $timeout = 300): Generator
    {
        $streamKey = "task_stream:{$this->taskId}";
        $lastId = '0-0';
        $completed = false;
        $startTime = microtime(true);

        while (!$completed) {
            // Check timeout
            if ((microtime(true) - $startTime) > $timeout) {
                throw new TaskTimeoutException($this->taskId);
            }

            // Check if task was cancelled
            if ($this->isCancelled($redis)) {
                $this->status = 'cancelled';
                return;
            }

            // Use phpredis xRead with blocking
            $results = $redis->xRead([$streamKey => $lastId], 10, 1000);

            if ($results && isset($results[$streamKey])) {
                foreach ($results[$streamKey] as $messageId => $messageData) {
                    if (isset($messageData['result'])) {
                        $result = json_decode($messageData['result'], true);
                        yield $result;
                        $lastId = $messageId;
                        // Handle non-string types properly
                        $this->combinedResult .= is_string($result) ? $result : json_encode($result);
                    }
                }
            }

            $taskJson = $redis->get("task_result:{$this->taskId}");
            if ($taskJson) {
                $taskData = json_decode($taskJson, true);
                $status = $taskData['status'] ?? null;

                if ($status === 'completed') {
                    $completed = true;
                    $this->status = 'completed';
                    $this->result = $this->combinedResult;
                } elseif ($status === 'failed') {
                    $errorMessage = $taskData['result'] ?? 'Task failed without an error message';
                    throw new TaskProcessingException(
                        $taskData['task_name'] ?? $this->taskName,
                        $errorMessage
                    );
                } elseif ($status === 'cancelled') {
                    $this->status = 'cancelled';
                    return;
                }
            }
        }
    }

    /**
     * Wait for and return the task result.
     *
     * @throws TaskTimeoutException If task doesn't complete within timeout
     * @throws TaskProcessingException If task failed
     */
    public function getResult(
        Redis $redis,
        ?int $timeout = null
    ): mixed {
        $timeout = $timeout ?? $this->timeout;
        $startTime = microtime(true);

        while ((microtime(true) - $startTime) < $timeout) {
            // Check if task was cancelled
            if ($this->isCancelled($redis)) {
                $this->status = 'cancelled';
                throw new TaskProcessingException($this->taskName, 'Task was cancelled');
            }

            $taskJson = $redis->get("task_result:{$this->taskId}");

            if ($taskJson) {
                $taskData = json_decode($taskJson, true);
                $this->result = $taskData['result'] ?? null;
                $this->status = $taskData['status'] ?? 'unknown';

                if ($this->status === 'failed') {
                    $errorMessage = $this->result ?? 'Task failed without an error message';
                    throw new TaskProcessingException(
                        $taskData['task_name'] ?? $this->taskName,
                        is_string($errorMessage) ? $errorMessage : json_encode($errorMessage)
                    );
                }

                if ($this->status === 'cancelled') {
                    throw new TaskProcessingException($this->taskName, 'Task was cancelled');
                }

                if ($this->status === 'completed') {
                    return $this->result;
                }
            }

            usleep(100000); // Sleep 100ms
        }

        throw new TaskTimeoutException($this->taskId);
    }

    /**
     * Get task status from Redis.
     */
    public function getStatus(Redis $redis): ?string
    {
        $taskJson = $redis->get("task:{$this->taskId}");

        if ($taskJson) {
            $taskData = json_decode($taskJson, true);
            return $taskData['status'] ?? null;
        }

        return null;
    }

    /**
     * Get the current progress of this task.
     *
     * @return array{progress: float, message: ?string, updated_at: float}|null
     */
    public function getProgress(Redis $redis): ?array
    {
        $progressData = $redis->get("task:{$this->taskId}:progress");

        if ($progressData) {
            return json_decode($progressData, true);
        }

        return null;
    }

    /**
     * Check if this task has been cancelled.
     */
    public function isCancelled(Redis $redis): bool
    {
        $cancelled = $redis->get("task:{$this->taskId}:cancelled");
        return $cancelled !== false && $cancelled !== null;
    }
}
