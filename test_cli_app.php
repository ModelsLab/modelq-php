<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use ModelsLab\ModelQ\ModelQ;

// Create and return the ModelQ instance
$modelq = new ModelQ(host: '127.0.0.1', port: 6379);

// Register tasks
$modelq->task('add_numbers', function (array $data): array {
    $a = $data['a'] ?? 0;
    $b = $data['b'] ?? 0;
    return ['sum' => $a + $b];
});

$modelq->task('multiply', function (array $data): int {
    return ($data['a'] ?? 0) * ($data['b'] ?? 0);
});

return $modelq;
