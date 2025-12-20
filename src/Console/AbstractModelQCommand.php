<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Console;

use ModelsLab\ModelQ\ModelQ;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base command class for ModelQ CLI commands.
 */
abstract class AbstractModelQCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'app-path',
            InputArgument::REQUIRED,
            'Path to the app file (e.g., "app.php")'
        );
    }

    /**
     * Load the ModelQ instance from the app file.
     */
    protected function loadAppInstance(InputInterface $input, OutputInterface $output): ?ModelQ
    {
        $appPath = $input->getArgument('app-path');

        if (!file_exists($appPath)) {
            $output->writeln("<error>App file not found: {$appPath}</error>");
            return null;
        }

        // Include the app file and expect it to return a ModelQ instance
        $app = require $appPath;

        if (!$app instanceof ModelQ) {
            $output->writeln("<error>App file must return a ModelQ instance</error>");
            return null;
        }

        return $app;
    }
}
