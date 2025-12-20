<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Console;

use Symfony\Component\Console\Application as BaseApplication;

/**
 * ModelQ CLI Application.
 */
class Application extends BaseApplication
{
    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct('ModelQ', self::VERSION);

        $this->add(new RunWorkersCommand());
        $this->add(new StatusCommand());
        $this->add(new ClearQueueCommand());
        $this->add(new RemoveTaskCommand());
        $this->add(new ListQueuedCommand());
    }
}
