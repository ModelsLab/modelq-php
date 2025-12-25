<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Unit;

use ModelsLab\ModelQ\Middleware\Middleware;
use ModelsLab\ModelQ\Task\Task;
use PHPUnit\Framework\TestCase;

class TestMiddleware extends Middleware
{
    public array $calls = [];

    public function beforeWorkerBoot(): void
    {
        $this->calls[] = 'beforeWorkerBoot';
    }

    public function afterWorkerBoot(): void
    {
        $this->calls[] = 'afterWorkerBoot';
    }

    public function beforeWorkerShutdown(): void
    {
        $this->calls[] = 'beforeWorkerShutdown';
    }

    public function afterWorkerShutdown(): void
    {
        $this->calls[] = 'afterWorkerShutdown';
    }

    public function beforeEnqueue(?Task $task): void
    {
        $this->calls[] = ['beforeEnqueue', $task?->taskId];
    }

    public function afterEnqueue(?Task $task): void
    {
        $this->calls[] = ['afterEnqueue', $task?->taskId];
    }

    public function onError(?Task $task, ?\Throwable $e): void
    {
        $this->calls[] = ['onError', $task?->taskId, $e?->getMessage()];
    }

    public function onTimeout(?Task $task): void
    {
        $this->calls[] = ['onTimeout', $task?->taskId];
    }
}

class ConcreteMiddleware extends Middleware
{
    // Empty concrete implementation for testing base class
}

class MiddlewareTest extends TestCase
{
    public function testMiddlewareCanBeInstantiated(): void
    {
        $middleware = new ConcreteMiddleware();
        $this->assertInstanceOf(Middleware::class, $middleware);
    }

    public function testBaseMiddlewareMethodsDoNothing(): void
    {
        $middleware = new ConcreteMiddleware();
        $task = new Task('test', []);

        // These should not throw
        $middleware->beforeWorkerBoot();
        $middleware->afterWorkerBoot();
        $middleware->beforeEnqueue($task);
        $middleware->afterEnqueue($task);
        $middleware->onError($task, new \Exception('test'));
        $middleware->onTimeout($task);

        $this->assertTrue(true);
    }

    public function testCustomMiddlewareCallsTracked(): void
    {
        $middleware = new TestMiddleware();
        $task = new Task('test_task', ['data' => 1]);

        $middleware->beforeWorkerBoot();
        $middleware->afterWorkerBoot();
        $middleware->beforeEnqueue($task);
        $middleware->afterEnqueue($task);

        $this->assertEquals('beforeWorkerBoot', $middleware->calls[0]);
        $this->assertEquals('afterWorkerBoot', $middleware->calls[1]);
        $this->assertEquals(['beforeEnqueue', $task->taskId], $middleware->calls[2]);
        $this->assertEquals(['afterEnqueue', $task->taskId], $middleware->calls[3]);
    }

    public function testOnErrorReceivesException(): void
    {
        $middleware = new TestMiddleware();
        $task = new Task('error_task', []);
        $exception = new \RuntimeException('Something went wrong');

        $middleware->onError($task, $exception);

        $this->assertEquals(['onError', $task->taskId, 'Something went wrong'], $middleware->calls[0]);
    }

    public function testOnTimeoutCalled(): void
    {
        $middleware = new TestMiddleware();
        $task = new Task('timeout_task', []);

        $middleware->onTimeout($task);

        $this->assertEquals(['onTimeout', $task->taskId], $middleware->calls[0]);
    }

    public function testMiddlewareWithNullTask(): void
    {
        $middleware = new TestMiddleware();

        $middleware->beforeEnqueue(null);
        $middleware->afterEnqueue(null);

        $this->assertEquals(['beforeEnqueue', null], $middleware->calls[0]);
        $this->assertEquals(['afterEnqueue', null], $middleware->calls[1]);
    }

    public function testBeforeWorkerShutdownCalled(): void
    {
        $middleware = new TestMiddleware();

        $middleware->beforeWorkerShutdown();

        $this->assertEquals('beforeWorkerShutdown', $middleware->calls[0]);
    }

    public function testAfterWorkerShutdownCalled(): void
    {
        $middleware = new TestMiddleware();

        $middleware->afterWorkerShutdown();

        $this->assertEquals('afterWorkerShutdown', $middleware->calls[0]);
    }

    public function testFullWorkerLifecycle(): void
    {
        $middleware = new TestMiddleware();
        $task = new Task('lifecycle_task', ['key' => 'value']);

        // Simulate full worker lifecycle
        $middleware->beforeWorkerBoot();
        $middleware->afterWorkerBoot();
        $middleware->beforeEnqueue($task);
        $middleware->afterEnqueue($task);
        $middleware->beforeWorkerShutdown();
        $middleware->afterWorkerShutdown();

        $this->assertEquals('beforeWorkerBoot', $middleware->calls[0]);
        $this->assertEquals('afterWorkerBoot', $middleware->calls[1]);
        $this->assertEquals(['beforeEnqueue', $task->taskId], $middleware->calls[2]);
        $this->assertEquals(['afterEnqueue', $task->taskId], $middleware->calls[3]);
        $this->assertEquals('beforeWorkerShutdown', $middleware->calls[4]);
        $this->assertEquals('afterWorkerShutdown', $middleware->calls[5]);
    }

    public function testBaseMiddlewareShutdownMethodsDoNothing(): void
    {
        $middleware = new ConcreteMiddleware();

        // These should not throw
        $middleware->beforeWorkerShutdown();
        $middleware->afterWorkerShutdown();

        $this->assertTrue(true);
    }

    public function testOnErrorWithNullException(): void
    {
        $middleware = new TestMiddleware();
        $task = new Task('error_task', []);

        $middleware->onError($task, null);

        $this->assertEquals(['onError', $task->taskId, null], $middleware->calls[0]);
    }

    public function testOnErrorWithNullTaskAndException(): void
    {
        $middleware = new TestMiddleware();

        $middleware->onError(null, null);

        $this->assertEquals(['onError', null, null], $middleware->calls[0]);
    }

    public function testMiddlewareWithDifferentExceptionTypes(): void
    {
        $middleware = new TestMiddleware();
        $task = new Task('exception_test', []);

        // Test with RuntimeException
        $middleware->onError($task, new \RuntimeException('Runtime error'));
        $this->assertEquals(['onError', $task->taskId, 'Runtime error'], $middleware->calls[0]);

        // Test with InvalidArgumentException
        $middleware->onError($task, new \InvalidArgumentException('Invalid arg'));
        $this->assertEquals(['onError', $task->taskId, 'Invalid arg'], $middleware->calls[1]);

        // Test with Error (Throwable, not Exception)
        $middleware->onError($task, new \TypeError('Type error'));
        $this->assertEquals(['onError', $task->taskId, 'Type error'], $middleware->calls[2]);
    }
}
