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
}
