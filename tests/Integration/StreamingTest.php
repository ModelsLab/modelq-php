<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Integration;

use ModelsLab\ModelQ\ModelQ;
use PHPUnit\Framework\TestCase;
use Redis;

class StreamingTest extends TestCase
{
    private Redis $redis;
    private ?int $workerPid = null;

    protected function setUp(): void
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->flushDb();
    }

    protected function tearDown(): void
    {
        $this->stopWorker();
        $this->redis->flushDb();
        $this->redis->close();
    }

    private function startWorker(): void
    {
        $workerScript = __DIR__ . '/worker_process.php';
        exec("php {$workerScript} > /tmp/modelq_phpunit_stream_worker.log 2>&1 & echo $!", $output);
        $this->workerPid = (int) ($output[0] ?? 0);
        sleep(2);
    }

    private function stopWorker(): void
    {
        if ($this->workerPid) {
            exec("kill {$this->workerPid} 2>/dev/null");
            $this->workerPid = null;
        }
        exec("pkill -f 'worker_process.php' 2>/dev/null");
    }

    public function testStreamingTask(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('stream_words', fn($data) => null, ['stream' => true]);

        $task = $modelq->enqueue('stream_words', ['sentence' => 'Hello World Test']);

        $words = [];
        foreach ($task->getStream($modelq->getRedisClient()) as $word) {
            $words[] = $word;
        }

        $this->assertEquals(['Hello', 'World', 'Test'], $words);
    }

    public function testStreamTaskCompletion(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('stream_words', fn($data) => null, ['stream' => true]);

        $task = $modelq->enqueue('stream_words', ['sentence' => 'A B']);

        $words = [];
        foreach ($task->getStream($modelq->getRedisClient()) as $word) {
            $words[] = $word;
        }

        $this->assertEquals('completed', $task->status);
        $this->assertCount(2, $words);
    }

    public function testStreamCombinedResult(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('stream_words', fn($data) => null, ['stream' => true]);

        $task = $modelq->enqueue('stream_words', ['sentence' => 'One Two']);

        foreach ($task->getStream($modelq->getRedisClient()) as $word) {
            // consume stream
        }

        $this->assertStringContainsString('One', $task->combinedResult);
        $this->assertStringContainsString('Two', $task->combinedResult);
    }
}
