# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Test Commands

```bash
# Install dependencies
composer install

# Run all tests (requires Redis running on localhost:6379)
./vendor/bin/phpunit

# Run only unit tests (no Redis required)
./vendor/bin/phpunit --testsuite Unit

# Run only integration tests (requires Redis)
./vendor/bin/phpunit --testsuite Integration

# Run a single test class
./vendor/bin/phpunit --filter TaskTest

# Run a single test method
./vendor/bin/phpunit --filter testTaskCreation

# Static analysis
composer analyse
# or: ./vendor/bin/phpstan analyse src --level=6

# CLI commands (require app-path that returns ModelQ instance)
./bin/modelq status <app-path>
./bin/modelq list-queued <app-path>
./bin/modelq clear-queue <app-path>
./bin/modelq remove-task <app-path> <task-id>
./bin/modelq run-workers <app-path> --workers=4
```

## Architecture Overview

ModelQ PHP is a Redis-backed task queue designed to work with Python ModelQ workers on GPU servers. PHP acts as the producer (web frontend), Python as the consumer (ML inference).

### Core Components

- **`src/ModelQ.php`** - Main orchestrator. Handles Redis connection, task registration (`task()`), enqueueing (`enqueue()`), worker loop (`startWorkers()`), and queue management methods. Uses phpredis extension.

- **`src/Task/Task.php`** - Task representation with UUID generation, serialization (`toArray()`/`fromArray()`), result polling (`getResult()`), and streaming support (`getStream()` uses Redis Streams via `xRead`).

- **`src/Middleware/Middleware.php`** - Abstract base class for lifecycle hooks: `beforeEnqueue`, `afterEnqueue`, `onError`, `onTimeout`, `beforeWorkerBoot`, etc.

- **`src/Console/`** - Symfony Console commands. `AbstractModelQCommand` loads the ModelQ instance from a PHP file path.

### Redis Key Structure

| Key | Type | Purpose |
|-----|------|---------|
| `task_queue` | List | Main FIFO queue |
| `task:{id}` | String | Task metadata JSON |
| `task_result:{id}` | String | Completed task result |
| `task_stream:{id}` | Stream | Streaming task output |
| `servers` | Hash | Registered worker servers |
| `processing_tasks` | Set | Currently processing task IDs |
| `delayed_tasks` | Sorted Set | Delayed tasks (score = exec time) |

### Task Flow

1. Producer calls `$modelq->enqueue('task_name', $data)` → Task pushed to `task_queue`
2. Worker pops from `task_queue` → moves task ID to `processing_tasks`
3. Handler executes (or yields for streaming → writes to `task_stream:{id}`)
4. Result stored in `task_result:{id}` → task removed from `processing_tasks`
5. Producer calls `$task->getResult()` (polls) or `$task->getStream()` (xRead)

### Streaming Tasks

Task handlers that return `Generator` enable streaming. Output chunks are written to Redis Streams, allowing real-time consumption on the producer side via `Task::getStream()`.

## Testing

- **Unit tests** (`tests/Unit/`): Test Task, Middleware, Exceptions in isolation
- **Integration tests** (`tests/Integration/`): Require Redis; test full ModelQ flow including worker spawning
- **Manual tests**: `test_manual.php`, `test_worker.php`, `test_streaming.php` for end-to-end verification
