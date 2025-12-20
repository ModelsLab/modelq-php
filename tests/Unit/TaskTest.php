<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Unit;

use ModelsLab\ModelQ\Task\Task;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase
{
    public function testTaskCreation(): void
    {
        $task = new Task('test_task', ['key' => 'value']);

        $this->assertEquals('test_task', $task->taskName);
        $this->assertEquals(['key' => 'value'], $task->payload);
        $this->assertEquals('queued', $task->status);
        $this->assertNotEmpty($task->taskId);
        $this->assertNotNull($task->createdAt);
    }

    public function testTaskIdIsUuid(): void
    {
        $task = new Task('test', []);

        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $task->taskId
        );
    }

    public function testTaskTimeout(): void
    {
        $task = new Task('test', [], timeout: 30);
        $this->assertEquals(30, $task->timeout);

        $taskDefault = new Task('test', []);
        $this->assertEquals(15, $taskDefault->timeout);
    }

    public function testToArray(): void
    {
        $task = new Task('serialize_test', ['data' => 123]);
        $task->status = 'processing';
        $task->result = ['output' => 'done'];
        $task->queuedAt = 1234567890.123;
        $task->startedAt = 1234567891.456;
        $task->finishedAt = 1234567892.789;
        $task->stream = true;

        $array = $task->toArray();

        $this->assertEquals($task->taskId, $array['task_id']);
        $this->assertEquals('serialize_test', $array['task_name']);
        $this->assertEquals(['data' => 123], $array['payload']);
        $this->assertEquals('processing', $array['status']);
        $this->assertEquals(['output' => 'done'], $array['result']);
        $this->assertEquals(1234567890.123, $array['queued_at']);
        $this->assertEquals(1234567891.456, $array['started_at']);
        $this->assertEquals(1234567892.789, $array['finished_at']);
        $this->assertTrue($array['stream']);
    }

    public function testFromArray(): void
    {
        $data = [
            'task_id' => 'test-uuid-12345',
            'task_name' => 'restore_test',
            'payload' => ['foo' => 'bar'],
            'status' => 'completed',
            'result' => 'success',
            'created_at' => 1234567890.0,
            'queued_at' => 1234567891.0,
            'started_at' => 1234567892.0,
            'finished_at' => 1234567893.0,
            'stream' => true,
        ];

        $task = Task::fromArray($data);

        $this->assertEquals('test-uuid-12345', $task->taskId);
        $this->assertEquals('restore_test', $task->taskName);
        $this->assertEquals(['foo' => 'bar'], $task->payload);
        $this->assertEquals('completed', $task->status);
        $this->assertEquals('success', $task->result);
        $this->assertEquals(1234567890.0, $task->createdAt);
        $this->assertEquals(1234567891.0, $task->queuedAt);
        $this->assertEquals(1234567892.0, $task->startedAt);
        $this->assertEquals(1234567893.0, $task->finishedAt);
        $this->assertTrue($task->stream);
    }

    public function testFromArrayWithMissingOptionalFields(): void
    {
        $data = [
            'task_id' => 'minimal-uuid',
            'task_name' => 'minimal_task',
            'payload' => [],
            'status' => 'queued',
        ];

        $task = Task::fromArray($data);

        $this->assertEquals('minimal-uuid', $task->taskId);
        $this->assertEquals('minimal_task', $task->taskName);
        $this->assertEquals('queued', $task->status);
        $this->assertNull($task->result);
        $this->assertFalse($task->stream);
    }

    public function testSerializationRoundTrip(): void
    {
        $original = new Task('roundtrip_test', ['nested' => ['data' => [1, 2, 3]]]);
        $original->status = 'completed';
        $original->result = ['output' => true];
        $original->stream = true;

        $array = $original->toArray();
        $restored = Task::fromArray($array);

        $this->assertEquals($original->taskId, $restored->taskId);
        $this->assertEquals($original->taskName, $restored->taskName);
        $this->assertEquals($original->payload, $restored->payload);
        $this->assertEquals($original->status, $restored->status);
        $this->assertEquals($original->result, $restored->result);
        $this->assertEquals($original->stream, $restored->stream);
    }

    public function testOriginalPayloadPreserved(): void
    {
        $task = new Task('test', ['original' => 'data']);
        $task->payload = ['modified' => 'payload'];

        $this->assertEquals(['original' => 'data'], $task->originalPayload);
        $this->assertEquals(['modified' => 'payload'], $task->payload);
    }
}
