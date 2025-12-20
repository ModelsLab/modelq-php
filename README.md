# ModelQ PHP

A lightweight PHP task queue library, alternative to traditional queue systems, optimized for ML inference workloads.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Features

- **Simple API** - Easy to use task registration and enqueueing
- **Redis-backed** - Fast and reliable task storage using Redis
- **Streaming Support** - Real-time streaming results for long-running tasks
- **Remote Workers** - Connect PHP frontend to Python GPU workers via Redis
- **Middleware Hooks** - Lifecycle hooks for monitoring and customization
- **Delayed Tasks** - Schedule tasks to run after a delay
- **CLI Tools** - Built-in commands for queue management
- **ML Optimized** - Designed for machine learning inference workloads

## Use Case: PHP Frontend + Python GPU Worker

ModelQ PHP is designed to work seamlessly with Python ModelQ workers running on GPU servers. This enables you to:

- Run your PHP web application on standard servers
- Offload ML inference tasks to dedicated GPU servers running Python
- Share a managed Redis instance (AWS ElastiCache, GCP Memorystore, etc.)

```
┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐
│   PHP Frontend  │─────▶│  Managed Redis  │◀─────│ Python Worker   │
│   (Web Server)  │      │  (AWS/GCP/etc)  │      │ (GPU Server)    │
└─────────────────┘      └─────────────────┘      └─────────────────┘
        │                                                  │
   Enqueue tasks                                    Process tasks
   Get results                                      (ML inference)
```

### Python Worker (GPU Server)

```python
from modelq import ModelQ

app = ModelQ(
    redis_host="your-redis.cache.amazonaws.com",
    redis_port=6379,
    redis_password="your-password"
)

@app.task("generate_image")
def generate_image(data):
    prompt = data.get("prompt")
    # Use GPU for image generation...
    return {"image_url": "https://cdn.example.com/generated.jpg"}

@app.task("llm_completion", stream=True)
def llm_completion(data):
    for token in generate_tokens(data["prompt"]):
        yield token

app.run_workers(num_workers=4)
```

### PHP Frontend (Web Server)

```php
<?php
use ModelsLab\ModelQ\ModelQ;

// Connect to the SAME Redis as Python worker
$modelq = new ModelQ(
    host: 'your-redis.cache.amazonaws.com',
    port: 6379,
    password: 'your-password'
);

// Register tasks (handlers empty - Python does the work)
$modelq->task('generate_image', fn($d) => null);
$modelq->task('llm_completion', fn($d) => null, ['stream' => true]);

// Enqueue task for Python worker
$task = $modelq->enqueue('generate_image', [
    'prompt' => 'A sunset over mountains'
]);

// Get result from Python worker
$result = $task->getResult($modelq->getRedisClient(), timeout: 120);
echo $result['image_url'];

// Or stream LLM responses
$task = $modelq->enqueue('llm_completion', ['prompt' => 'Hello']);
foreach ($task->getStream($modelq->getRedisClient()) as $token) {
    echo $token;
}
```

See [examples/remote_worker.php](examples/remote_worker.php) for a complete example.

## Requirements

- PHP 8.1 or higher
- Redis server
- phpredis extension (`ext-redis`)

## Installation

```bash
composer require modelslab/modelq
```

Make sure you have the phpredis extension installed:

```bash
# Ubuntu/Debian
sudo apt-get install php-redis

# macOS with Homebrew
pecl install redis
```

## Quick Start

### 1. Create a Worker

```php
<?php

require_once 'vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

$modelq = new ModelQ(host: '127.0.0.1', port: 6379);

// Register a task handler
$modelq->task('process_image', function (array $data): array {
    $imageUrl = $data['url'];
    // Process the image...
    return ['status' => 'processed', 'url' => $imageUrl];
});

// Start the worker
$modelq->startWorkers(numWorkers: 2);
```

### 2. Enqueue Tasks

```php
<?php

require_once 'vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

$modelq = new ModelQ(host: '127.0.0.1', port: 6379);

// Register the task (handler can be empty on producer side)
$modelq->task('process_image', fn($data) => null);

// Enqueue a task
$task = $modelq->enqueue('process_image', ['url' => 'https://example.com/image.jpg']);

// Wait for the result
$result = $task->getResult($modelq->getRedisClient(), timeout: 30);
echo "Result: " . json_encode($result);
```

## Usage

### Basic Task Registration

```php
use ModelsLab\ModelQ\ModelQ;

$modelq = new ModelQ(host: '127.0.0.1', port: 6379);

// Simple task
$modelq->task('add_numbers', function (array $data): array {
    return ['sum' => $data['a'] + $data['b']];
});

// Task with options
$modelq->task('long_running_task', function (array $data): mixed {
    // Long running operation...
    return $result;
}, [
    'timeout' => 300,  // 5 minute timeout
    'retries' => 3,    // Retry up to 3 times on failure
]);
```

### Enqueueing Tasks

```php
// Basic enqueue
$task = $modelq->enqueue('add_numbers', ['a' => 5, 'b' => 3]);

// Get the task ID
echo "Task ID: " . $task->taskId;

// Wait for result (blocking)
$result = $task->getResult($modelq->getRedisClient(), timeout: 10);
```

### Streaming Tasks

For tasks that produce incremental output (like ML text generation):

```php
// Register a streaming task
$modelq->task('generate_text', function (array $data): Generator {
    $prompt = $data['prompt'];
    $words = ['Hello', 'World', 'from', 'ModelQ'];

    foreach ($words as $word) {
        usleep(100000); // Simulate processing
        yield $word;
    }
}, ['stream' => true]);

// Consume the stream
$task = $modelq->enqueue('generate_text', ['prompt' => 'Hello']);

foreach ($task->getStream($modelq->getRedisClient()) as $chunk) {
    echo $chunk . " ";
}
// Output: Hello World from ModelQ
```

### Queue Management

```php
// Get all queued tasks
$tasks = $modelq->getAllQueuedTasks();
foreach ($tasks as $task) {
    echo "Task: {$task['task_id']} - {$task['task_name']}\n";
}

// Get task status
$status = $modelq->getTaskStatus($taskId);
echo "Status: $status"; // queued, processing, completed, failed

// Remove a task from queue
$modelq->removeTaskFromQueue($taskId);

// Clear the entire queue
$modelq->deleteQueue();

// Get currently processing tasks
$processing = $modelq->getProcessingTasks();
```

### Delayed Tasks

```php
// Enqueue a task to run after 60 seconds
$taskData = [
    'task_id' => 'delayed-' . uniqid(),
    'task_name' => 'send_reminder',
    'payload' => ['user_id' => 123],
    'status' => 'queued',
];

$modelq->enqueueDelayedTask($taskData, delaySeconds: 60);
```

### Middleware

Create custom middleware for lifecycle hooks:

```php
use ModelsLab\ModelQ\Middleware\Middleware;
use ModelsLab\ModelQ\Task\Task;

class LoggingMiddleware extends Middleware
{
    public function beforeEnqueue(?Task $task): void
    {
        echo "Enqueueing task: {$task->taskName}\n";
    }

    public function afterEnqueue(?Task $task): void
    {
        echo "Task enqueued: {$task->taskId}\n";
    }

    public function beforeWorkerBoot(): void
    {
        echo "Worker starting...\n";
    }

    public function afterWorkerBoot(): void
    {
        echo "Worker ready!\n";
    }

    public function onError(?Task $task, ?\Throwable $error): void
    {
        echo "Task {$task->taskId} failed: {$error->getMessage()}\n";
    }

    public function onTimeout(?Task $task): void
    {
        echo "Task {$task->taskId} timed out\n";
    }
}

// Apply middleware
$modelq->setMiddleware(new LoggingMiddleware());
```

### Custom Redis Client

```php
// Use your own Redis connection
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->auth('your-password');
$redis->select(2);

$modelq = new ModelQ(redisClient: $redis);
```

### Error Handling

```php
use ModelsLab\ModelQ\Exception\TaskTimeoutException;
use ModelsLab\ModelQ\Exception\TaskProcessingException;
use ModelsLab\ModelQ\Exception\RetryTaskException;

try {
    $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
} catch (TaskTimeoutException $e) {
    echo "Task {$e->taskId} timed out\n";
} catch (TaskProcessingException $e) {
    echo "Task {$e->taskName} failed: {$e->getMessage()}\n";
}

// Inside a task handler, trigger a retry
$modelq->task('flaky_task', function (array $data): mixed {
    if (someCondition()) {
        throw new RetryTaskException('Temporary failure, retrying...');
    }
    return $result;
});
```

## CLI Commands

ModelQ includes CLI commands for queue management:

```bash
# Show queue status
./vendor/bin/modelq status app.php

# List all queued tasks
./vendor/bin/modelq list-queued app.php

# Remove a specific task
./vendor/bin/modelq remove-task app.php <task-id>

# Clear the entire queue
./vendor/bin/modelq clear-queue app.php

# Start workers
./vendor/bin/modelq run-workers app.php --workers=4
```

The `app.php` file should return a configured ModelQ instance:

```php
<?php
// app.php
require_once 'vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

$modelq = new ModelQ(host: '127.0.0.1', port: 6379);

$modelq->task('my_task', function (array $data): mixed {
    // Task handler
    return $data;
});

return $modelq;
```

## Configuration Options

```php
$modelq = new ModelQ(
    redisClient: null,           // Optional: Provide your own Redis client
    host: '127.0.0.1',           // Redis host
    port: 6379,                  // Redis port
    db: 0,                       // Redis database number
    password: null,              // Redis password
    serverId: null,              // Custom server ID (defaults to hostname)
    webhookUrl: null,            // Webhook URL for task completion notifications
    requeueThreshold: null,      // Time before requeuing stuck tasks
    delaySeconds: 30,            // Default delay for delayed tasks
    logger: null,                // PSR-3 logger instance
);
```

## Task Options

```php
$modelq->task('my_task', $handler, [
    'timeout' => 60,     // Task timeout in seconds
    'stream' => false,   // Enable streaming mode
    'retries' => 3,      // Number of retry attempts
]);
```

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Producer  │────▶│    Redis    │◀────│   Worker    │
│  (Enqueue)  │     │   (Queue)   │     │  (Process)  │
└─────────────┘     └─────────────┘     └─────────────┘
                           │
                           ▼
                    ┌─────────────┐
                    │   Results   │
                    │   Storage   │
                    └─────────────┘
```

## Redis Keys

ModelQ uses the following Redis keys:

| Key Pattern | Type | Description |
|-------------|------|-------------|
| `task_queue` | List | Main task queue |
| `task:{id}` | String | Task data |
| `task_result:{id}` | String | Task result |
| `task_stream:{id}` | Stream | Streaming task output |
| `servers` | Hash | Registered worker servers |
| `processing_tasks` | Set | Currently processing tasks |
| `delayed_tasks` | Sorted Set | Delayed tasks (score = execution time) |

## Testing

```bash
# Run PHPUnit tests
./vendor/bin/phpunit

# Run manual tests
php test_manual.php

# Run worker tests
php test_worker.php

# Run streaming tests
php test_streaming.php
```

## Examples

Check the `examples/` directory for complete working examples:

- `examples/basic_usage.php` - Basic task queue operations
- `examples/producer.php` - Enqueueing tasks and getting results
- `examples/worker.php` - Worker process setup
- `examples/streaming_example.php` - Streaming task example
- `examples/middleware_example.php` - Custom middleware usage
- `examples/queue_management.php` - Queue management operations
- `examples/remote_worker.php` - **PHP + Remote Python GPU Worker**

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Related Projects

- [ModelQ Python](https://github.com/ModelsLab/modelq) - The original Python implementation
