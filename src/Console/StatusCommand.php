<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'status',
    description: 'Show ModelQ status'
)]
class StatusCommand extends AbstractModelQCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->loadAppInstance($input, $output);
        if (!$app) {
            return self::FAILURE;
        }

        try {
            $servers = $app->getRegisteredServerIds();
            $queuedTasks = $app->getAllQueuedTasks();
            $processingTasks = $app->getProcessingTasks();

            $output->writeln("ModelQ Status:");
            $output->writeln("   Registered Servers: " . count($servers));
            $output->writeln("   Queued Tasks: " . count($queuedTasks));
            $output->writeln("   Processing Tasks: " . count($processingTasks));
            $output->writeln("   Allowed Tasks: " . implode(', ', $app->getAllowedTasks() ?: ['None']));

            if (!empty($servers)) {
                $output->writeln("\nActive Servers:");
                foreach ($servers as $serverId) {
                    $output->writeln("   - {$serverId}");
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed to get status: {$e->getMessage()}</error>");
            return self::FAILURE;
        }
    }
}
