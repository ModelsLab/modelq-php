<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'clear-queue',
    description: 'Clear all tasks from the queue'
)]
class ClearQueueCommand extends AbstractModelQCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->loadAppInstance($input, $output);
        if (!$app) {
            return self::FAILURE;
        }

        try {
            $app->deleteQueue();
            $output->writeln("Cleared all tasks from the queue.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed to clear queue: {$e->getMessage()}</error>");
            return self::FAILURE;
        }
    }
}
