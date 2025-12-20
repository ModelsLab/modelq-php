<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Exception;

use Exception;

/**
 * Exception thrown when a task times out waiting for a result.
 */
class TaskTimeoutException extends Exception
{
    public function __construct(
        public readonly string $taskId,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $message = $message ?: "Task {$taskId} timed out waiting for result.";
        parent::__construct($message, $code, $previous);
    }
}
