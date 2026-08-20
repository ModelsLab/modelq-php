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
    /**
     * Result-polling cadence, in microseconds.
     *
     * The loop used to wake every 100ms for the whole wait. Two GETs per wake
     * against a Redis 271ms away is ~100 pointless round trips on a 30s wait,
     * and the caller's worker is pinned for all of it -- on flux_klein only
     * 5.7% of tasks finish inside that window, so 94% of those requests paid
     * the full cost to learn nothing.
     *
     * Backing off rather than moving straight to a flat 1s keeps the fast
     * endpoints fast: realtime_turbo runs in 1.18s at p50 and 100% of its tasks
     * finish inside 10s, so a flat 1s poll would add up to a second to a
     * one-second job. Starting at 100ms and doubling to a 1s ceiling gives
     * sub-second tasks the tight loop they need and long ones a quiet one.
     */
    public const POLL_MIN_INTERVAL_US = 100000;   // 100ms

    public const POLL_MAX_INTERVAL_US = 1000000;  // 1s

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
    public array $additionalParams = [];

    public function __construct(
        string $taskName,
        array $payload,
        int $timeout = 15,
        ?string $taskId = null,
        ?array $additionalParams = null
    ) {
        $this->taskId = $taskId ?? $this->generateUuid();
        $this->taskName = $taskName;
        $this->payload = $payload;
        $this->originalPayload = $payload;
        $this->createdAt = microtime(true);
        $this->timeout = $timeout;
        $this->additionalParams = $additionalParams ?? [];
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
        $baseArray = [
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

        // Add additional_params to the base response if they exist
        if (!empty($this->additionalParams)) {
            $baseArray = array_merge($baseArray, $this->additionalParams);
        }

        return $baseArray;
    }

    /**
     * Create a Task instance from array data.
     */
    public static function fromArray(array $data): self
    {
        // Extract additional_params from data (any keys not in the standard set)
        $standardKeys = [
            'task_id', 'task_name', 'payload', 'status', 'result',
            'created_at', 'queued_at', 'started_at', 'finished_at', 'stream'
        ];
        $additionalParams = array_diff_key($data, array_flip($standardKeys));

        $task = new self(
            taskName: $data['task_name'],
            payload: $data['payload'],
            additionalParams: !empty($additionalParams) ? $additionalParams : null
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
        $interval = self::POLL_MIN_INTERVAL_US;

        while ((microtime(true) - $startTime) < $timeout) {
            [$cancelled, $taskJson] = $this->readResultState($redis);

            if ($cancelled) {
                $this->status = 'cancelled';
                throw new TaskProcessingException($this->taskName, 'Task was cancelled');
            }

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

            usleep($interval);
            $interval = min($interval * 2, self::POLL_MAX_INTERVAL_US);
        }

        throw new TaskTimeoutException($this->taskId);
    }

    /**
     * Read the cancel flag and the result in a single round trip.
     *
     * These were two sequential GETs. Against a Redis one ocean away that is
     * two waits per poll for information that is always read together.
     *
     * @return array{0: bool, 1: string|false|null}
     */
    private function readResultState(Redis $redis): array
    {
        $replies = $redis->multi(Redis::PIPELINE)
            ->get("task:{$this->taskId}:cancelled")
            ->get("task_result:{$this->taskId}")
            ->exec();

        if (!is_array($replies)) {
            // A pipeline that did not come back cleanly says nothing about the
            // task. Report "not cancelled, no result yet" and let the loop
            // decide on the next pass, exactly as a pair of empty GETs would.
            return [false, null];
        }

        // Match isCancelled() exactly: any stored value means cancelled, so a
        // literal "0" must not be read as "still running".
        $cancelFlag = $replies[0] ?? false;

        return [$cancelFlag !== false && $cancelFlag !== null, $replies[1] ?? null];
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
