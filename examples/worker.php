<?php

/**
 * ModelQ PHP - Worker Example
 *
 * This example demonstrates how to set up a worker process
 * that processes tasks from the queue.
 *
 * Run this script to start processing tasks:
 *   $ php examples/worker.php
 *
 * You can specify the number of workers:
 *   $ php examples/worker.php --workers=4
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;
use ModelsLab\ModelQ\Exception\RetryTaskException;

echo "=== ModelQ PHP Worker ===\n\n";

// Parse command line arguments
$numWorkers = 1;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--workers=')) {
        $numWorkers = (int) substr($arg, 10);
    }
}

// Initialize ModelQ
$modelq = new ModelQ(
    host: '127.0.0.1',
    port: 6379
);

// ============================================
// Register Task Handlers
// ============================================

// Simple addition task
$modelq->task('add_numbers', function (array $data): array {
    $a = $data['a'] ?? 0;
    $b = $data['b'] ?? 0;
    echo "  Processing add_numbers: {$a} + {$b}\n";
    return ['sum' => $a + $b];
});

// Multiplication task
$modelq->task('multiply', function (array $data): int {
    $a = $data['a'] ?? 0;
    $b = $data['b'] ?? 0;
    echo "  Processing multiply: {$a} * {$b}\n";
    return $a * $b;
});

// Text processing task
$modelq->task('process_text', function (array $data): array {
    $text = $data['text'] ?? '';
    echo "  Processing text: \"{$text}\"\n";
    return [
        'original' => $text,
        'uppercase' => strtoupper($text),
        'lowercase' => strtolower($text),
        'word_count' => str_word_count($text),
        'char_count' => strlen($text),
    ];
});

// Echo task (returns input data)
$modelq->task('echo_data', function (array $data): array {
    echo "  Processing echo_data\n";
    return $data;
});

// Slow task for testing timeouts
$modelq->task('slow_task', function (array $data): string {
    $delay = $data['delay'] ?? 2;
    echo "  Processing slow_task (sleeping {$delay}s)...\n";
    sleep($delay);
    return "Completed after {$delay} second(s)";
}, ['timeout' => 60]);

// Task that might fail and retry
$modelq->task('flaky_task', function (array $data): string {
    $shouldFail = $data['fail'] ?? false;

    if ($shouldFail) {
        echo "  flaky_task: Simulating failure, requesting retry\n";
        throw new RetryTaskException('Temporary failure');
    }

    echo "  Processing flaky_task: Success\n";
    return 'Success';
}, ['retries' => 3]);

// Image processing simulation
$modelq->task('process_image', function (array $data): array {
    $url = $data['url'] ?? 'unknown';
    $width = $data['width'] ?? 1024;
    $height = $data['height'] ?? 768;

    echo "  Processing image: {$url}\n";

    // Simulate image processing
    usleep(500000);

    return [
        'url' => $url,
        'width' => $width,
        'height' => $height,
        'format' => 'jpeg',
        'processed' => true,
        'timestamp' => time(),
    ];
});

// Streaming text generation (for ML-like workloads)
$modelq->task('generate_text', function (array $data): Generator {
    $prompt = $data['prompt'] ?? 'Hello';
    $words = explode(' ', "This is a generated response to: {$prompt}");

    echo "  Streaming generate_text for prompt: \"{$prompt}\"\n";

    foreach ($words as $word) {
        usleep(100000); // 100ms delay between words
        yield $word . ' ';
    }
}, ['stream' => true]);

// Streaming number generator
$modelq->task('stream_numbers', function (array $data): Generator {
    $count = $data['count'] ?? 10;

    echo "  Streaming {$count} numbers\n";

    for ($i = 1; $i <= $count; $i++) {
        usleep(50000); // 50ms delay
        yield ['number' => $i, 'square' => $i * $i];
    }
}, ['stream' => true]);

echo "Registered tasks:\n";
foreach ($modelq->getAllowedTasks() as $taskName) {
    echo "  - {$taskName}\n";
}

echo "\nStarting {$numWorkers} worker(s)...\n";
echo "Press Ctrl+C to stop.\n\n";

// Start workers
$modelq->startWorkers($numWorkers);
