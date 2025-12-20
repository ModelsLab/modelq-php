<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

$modelq = new ModelQ(host: '127.0.0.1', port: 6379);

$modelq->task('add_numbers', function (array $data): array {
    $a = $data['a'] ?? 0;
    $b = $data['b'] ?? 0;
    return ['sum' => $a + $b];
});

$modelq->task('multiply', function (array $data): int {
    $a = $data['a'] ?? 0;
    $b = $data['b'] ?? 0;
    return $a * $b;
});

$modelq->task('echo_data', function (array $data): array {
    return $data;
});

$modelq->task('slow_task', function (array $data): string {
    sleep(1);
    return 'completed after delay';
});

$modelq->task('stream_words', function (array $data): Generator {
    $sentence = $data['sentence'] ?? 'Hello World';
    $words = explode(' ', $sentence);

    foreach ($words as $word) {
        usleep(100000);
        yield $word;
    }
}, ['stream' => true]);

$modelq->startWorkers(1);
