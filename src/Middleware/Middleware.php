<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Middleware;

use ModelsLab\ModelQ\Task\Task;
use Throwable;

/**
 * Base middleware class for task lifecycle hooks.
 *
 * Extend this class and override the methods you need to hook into.
 */
abstract class Middleware
{
    /**
     * Execute the appropriate middleware method based on the event.
     */
    public function execute(string $event, ?Task $task = null, ?Throwable $error = null): void
    {
        match ($event) {
            'before_worker_boot' => $this->beforeWorkerBoot(),
            'after_worker_boot' => $this->afterWorkerBoot(),
            'before_worker_shutdown' => $this->beforeWorkerShutdown(),
            'after_worker_shutdown' => $this->afterWorkerShutdown(),
            'before_enqueue' => $this->beforeEnqueue($task),
            'after_enqueue' => $this->afterEnqueue($task),
            'on_timeout' => $this->onTimeout($task),
            'on_error' => $this->onError($task, $error),
            default => null,
        };
    }

    /**
     * Called before the worker process starts up.
     */
    public function beforeWorkerBoot(): void
    {
        // Override in subclass
    }

    /**
     * Called after the worker process has started.
     */
    public function afterWorkerBoot(): void
    {
        // Override in subclass
    }

    /**
     * Called right before the worker is about to shut down.
     */
    public function beforeWorkerShutdown(): void
    {
        // Override in subclass
    }

    /**
     * Called after the worker has shut down.
     */
    public function afterWorkerShutdown(): void
    {
        // Override in subclass
    }

    /**
     * Called before a task is enqueued.
     */
    public function beforeEnqueue(?Task $task): void
    {
        // Override in subclass
    }

    /**
     * Called after a task is enqueued.
     */
    public function afterEnqueue(?Task $task): void
    {
        // Override in subclass
    }

    /**
     * Called when a task times out.
     */
    public function onTimeout(?Task $task): void
    {
        // Override in subclass
    }

    /**
     * Called when a task throws an error.
     */
    public function onError(?Task $task, ?Throwable $error): void
    {
        // Override in subclass
    }
}
