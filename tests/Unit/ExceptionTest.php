<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Unit;

use ModelsLab\ModelQ\Exception\RetryTaskException;
use ModelsLab\ModelQ\Exception\TaskProcessingException;
use ModelsLab\ModelQ\Exception\TaskTimeoutException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testTaskTimeoutException(): void
    {
        $exception = new TaskTimeoutException('task-uuid-123');

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('task-uuid-123', $exception->taskId);
        $this->assertStringContainsString('task-uuid-123', $exception->getMessage());
        $this->assertStringContainsString('timed out', $exception->getMessage());
    }

    public function testTaskTimeoutExceptionWithCustomMessage(): void
    {
        $exception = new TaskTimeoutException('task-123', 'Custom timeout message');

        $this->assertEquals('Custom timeout message', $exception->getMessage());
    }

    public function testTaskProcessingException(): void
    {
        $exception = new TaskProcessingException('process_data', 'Invalid input data');

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('process_data', $exception->taskName);
        $this->assertStringContainsString('process_data', $exception->getMessage());
        $this->assertStringContainsString('Invalid input data', $exception->getMessage());
    }

    public function testRetryTaskException(): void
    {
        $exception = new RetryTaskException();

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertStringContainsString('Task requested retry', $exception->getMessage());
    }

    public function testRetryTaskExceptionWithCustomMessage(): void
    {
        $exception = new RetryTaskException('Rate limited, retrying');

        $this->assertEquals('Rate limited, retrying', $exception->getMessage());
    }

    public function testExceptionsAreCatchable(): void
    {
        try {
            throw new TaskTimeoutException('test-task');
        } catch (TaskTimeoutException $e) {
            $this->assertEquals('test-task', $e->taskId);
        }

        try {
            throw new TaskProcessingException('my_task', 'Error details');
        } catch (TaskProcessingException $e) {
            $this->assertEquals('my_task', $e->taskName);
        }

        try {
            throw new RetryTaskException('Custom retry');
        } catch (RetryTaskException $e) {
            $this->assertStringContainsString('Custom retry', $e->getMessage());
        }
    }

    public function testExceptionsCanBeChained(): void
    {
        $cause = new \RuntimeException('Root cause');
        $exception = new TaskProcessingException('task', 'Failed', 0, $cause);

        $this->assertSame($cause, $exception->getPrevious());
    }

    public function testTaskTimeoutExceptionWithEmptyTaskId(): void
    {
        $exception = new TaskTimeoutException('');

        $this->assertEquals('', $exception->taskId);
        $this->assertStringContainsString('timed out', $exception->getMessage());
    }

    public function testTaskProcessingExceptionWithEmptyError(): void
    {
        $exception = new TaskProcessingException('task_name', '');

        $this->assertEquals('task_name', $exception->taskName);
        $this->assertNotEmpty($exception->getMessage());
    }

    public function testTaskProcessingExceptionWithSpecialCharacters(): void
    {
        $exception = new TaskProcessingException(
            'special_task',
            "Error with\nnewlines\tand\ttabs"
        );

        $this->assertStringContainsString('newlines', $exception->getMessage());
        $this->assertStringContainsString('tabs', $exception->getMessage());
    }

    public function testTaskTimeoutExceptionWithUnicodeTaskId(): void
    {
        $exception = new TaskTimeoutException('task-🚀-123');

        $this->assertEquals('task-🚀-123', $exception->taskId);
    }

    public function testRetryTaskExceptionCanBeRethrown(): void
    {
        $original = new RetryTaskException('First attempt');

        try {
            try {
                throw $original;
            } catch (RetryTaskException $e) {
                throw new RetryTaskException('Second attempt', 0, $e);
            }
        } catch (RetryTaskException $e) {
            $this->assertEquals('Second attempt', $e->getMessage());
            $this->assertSame($original, $e->getPrevious());
        }
    }

    public function testExceptionCodePreserved(): void
    {
        $exception = new TaskProcessingException('task', 'Error', 42);

        $this->assertEquals(42, $exception->getCode());
    }

    public function testDeepExceptionChaining(): void
    {
        $root = new \Exception('Root');
        $level1 = new \RuntimeException('Level 1', 0, $root);
        $level2 = new TaskProcessingException('task', 'Level 2', 0, $level1);

        $this->assertSame($level1, $level2->getPrevious());
        $this->assertSame($root, $level2->getPrevious()->getPrevious());
    }
}
