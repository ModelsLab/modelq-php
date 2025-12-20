<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Integration;

use ModelsLab\ModelQ\ModelQ;
use PHPUnit\Framework\TestCase;
use Redis;

class WorkerTest extends TestCase
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
        exec("php {$workerScript} > /tmp/modelq_phpunit_worker.log 2>&1 & echo $!", $output);
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

    public function testTaskExecution(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('add_numbers', fn($data) => null);

        $task = $modelq->enqueue('add_numbers', ['a' => 5, 'b' => 3]);
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);

        $this->assertIsArray($result);
        $this->assertEquals(8, $result['sum']);
    }

    public function testTaskWithReturnValue(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('multiply', fn($data) => null);

        $task = $modelq->enqueue('multiply', ['a' => 7, 'b' => 6]);
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);

        $this->assertEquals(42, $result);
    }

    public function testEchoTask(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('echo_data', fn($data) => null);

        $testData = ['name' => 'ModelQ', 'version' => '1.0', 'nested' => ['key' => 'value']];
        $task = $modelq->enqueue('echo_data', $testData);
        $result = $task->getResult($modelq->getRedisClient(), timeout: 10);

        $this->assertEquals($testData, $result);
    }

    public function testMultipleConcurrentTasks(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('add_numbers', fn($data) => null);

        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $modelq->enqueue('add_numbers', ['a' => $i, 'b' => $i * 2]);
        }

        $results = [];
        foreach ($tasks as $task) {
            $result = $task->getResult($modelq->getRedisClient(), timeout: 10);
            $results[] = $result['sum'];
        }

        $expected = [0, 3, 6, 9, 12];
        $this->assertEquals($expected, $results);
    }

    public function testTaskStatusTransition(): void
    {
        $this->startWorker();

        $modelq = new ModelQ(host: '127.0.0.1', port: 6379);
        $modelq->task('add_numbers', fn($data) => null);

        $task = $modelq->enqueue('add_numbers', ['a' => 1, 'b' => 1]);
        $task->getResult($modelq->getRedisClient(), timeout: 10);

        $status = $modelq->getTaskStatus($task->taskId);
        $this->assertEquals('completed', $status);
    }
}
