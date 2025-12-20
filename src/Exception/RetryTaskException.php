<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Exception;

use Exception;

/**
 * Exception to be thrown within a task function to manually trigger a retry.
 */
class RetryTaskException extends Exception
{
    public function __construct(
        string $message = 'Task requested retry',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
