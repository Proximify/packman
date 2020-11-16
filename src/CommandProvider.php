<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        $names = [Command::INIT_CMD, Command::UPDATE_CMD];
        $commands = [];

        foreach ($names as $name) {
            $commands[] = new Command($name);
        }

        return $commands;
    }
}

/**
 * One class for several Packman plugin commands.
 */
class Command extends BaseCommand
{
    const INIT_CMD = 'packman-init';
    const UPDATE_CMD = 'packman-update';

    private $cmdName;

    function __construct(string $name)
    {
        parent::BaseCommand();

        $this->cmdName = $name;
    }

    protected function configure()
    {
        $this->setName($this->cmdName);
    }

    /**
     * Execute the plugin command. 
     * 
     * @param InputInterface $input
     * @param OutputInterface $output Console output. 
     * E.g. $output->writeln('Executing ...');
     * @link https://github.com/symfony/symfony/blob/5.x/src/Symfony/Component/Console/Output/OutputInterface.php
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pm = new Packman();

        switch ($this->cmdName) {
            case 'packman-init':
                return $pm->init();
            case 'packman-update':
                return $pm->updateSatis();
        }
    }
}
