<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'remove-task',
    description: 'Remove a task from the queue by task ID'
)]
class RemoveTaskCommand extends AbstractModelQCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument(
            'task-id',
            InputArgument::REQUIRED,
            'The task ID to remove'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->loadAppInstance($input, $output);
        if (!$app) {
            return self::FAILURE;
        }

        $taskId = $input->getArgument('task-id');

        try {
            $removed = $app->removeTaskFromQueue($taskId);
            if ($removed) {
                $output->writeln("Task {$taskId} removed from queue.");
            } else {
                $output->writeln("<comment>Task {$taskId} not found in queue.</comment>");
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed to remove task: {$e->getMessage()}</error>");
            return self::FAILURE;
        }
    }
}
