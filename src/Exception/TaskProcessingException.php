<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Exception;

use Exception;

/**
 * Exception thrown when an error occurs during task processing.
 */
class TaskProcessingException extends Exception
{
    public function __construct(
        public readonly string $taskName,
        string $errorMessage,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $message = "Error processing task {$taskName}: {$errorMessage}";
        parent::__construct($message, $code, $previous);
    }
}
