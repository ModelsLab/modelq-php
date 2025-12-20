<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'list-queued',
    description: 'List all currently queued tasks'
)]
class ListQueuedCommand extends AbstractModelQCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->loadAppInstance($input, $output);
        if (!$app) {
            return self::FAILURE;
        }

        try {
            $tasks = $app->getAllQueuedTasks();

            if (empty($tasks)) {
                $output->writeln("No tasks in queue.");
                return self::SUCCESS;
            }

            $output->writeln("Queued Tasks (" . count($tasks) . "):");
            foreach ($tasks as $task) {
                $taskId = $task['task_id'] ?? 'unknown';
                $taskName = $task['task_name'] ?? 'unknown';
                $output->writeln(" - {$taskId} ({$taskName})");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed to list queued tasks: {$e->getMessage()}</error>");
            return self::FAILURE;
        }
    }
}
