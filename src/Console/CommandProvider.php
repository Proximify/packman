<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\Packman\Console;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

/**
 * Configuration of Symfony console commands.
 * 
 * @link https://symfony.com/doc/2.6/cookbook/console/console_command.html
 * @link https://symfony.com/doc/current/console/style.html
 */
class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return [
            new StartCommand(),
            new BuildCommand(),
            new PurgeCommand(),
            new ResetCommand(),
            new StopCommand(),
        ];
    }
}
