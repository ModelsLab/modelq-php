<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'run-workers',
    description: 'Start ModelQ worker processes'
)]
class RunWorkersCommand extends AbstractModelQCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption(
                'workers',
                'w',
                InputOption::VALUE_OPTIONAL,
                'Number of worker threads (for future use)',
                1
            )
            ->addOption(
                'log-level',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Logging level',
                'INFO'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->loadAppInstance($input, $output);
        if (!$app) {
            return self::FAILURE;
        }

        $workers = (int) $input->getOption('workers');
        $logLevel = $input->getOption('log-level');

        $output->writeln("Starting ModelQ workers...");
        $output->writeln("   Workers: {$workers}");
        $output->writeln("   Log Level: {$logLevel}");
        $output->writeln("   Registered Tasks: " . implode(', ', $app->getAllowedTasks() ?: ['None']));
        $output->writeln("   Press Ctrl+C to stop");
        $output->writeln(str_repeat('-', 50));

        // Handle signals for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($app, $output) {
                $output->writeln("\nReceived SIGINT. Shutting down gracefully...");
                $app->stop();
            });
            pcntl_signal(SIGTERM, function () use ($app, $output) {
                $output->writeln("\nReceived SIGTERM. Shutting down gracefully...");
                $app->stop();
            });
        }

        try {
            $output->writeln("Workers are running. Waiting for tasks...");
            $app->startWorkers($workers);
        } catch (\Throwable $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return self::FAILURE;
        }

        $output->writeln("Shutting down workers...");
        $output->writeln("Shutdown complete");

        return self::SUCCESS;
    }
}
