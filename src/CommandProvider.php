<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        $names = [
            Command::INIT_CMD, Command::UPDATE_CMD,
            Command::ALT_INIT_CMD, Command::ALT_UPDATE_CMD
        ];

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
    const ALT_INIT_CMD = 'init-packman';
    const UPDATE_CMD = 'packman-update';
    const ALT_UPDATE_CMD = 'update-packman';

    private $cmdName;

    public function __construct(string $name)
    {
        // Do this BEFORE calling the parent constructor because the base
        // constructor calls configure().
        $this->cmdName = $name;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName($this->cmdName);
        // $this->addArgument('useTokens', InputArgument::OPTIONAL, false);
    }

    /**
     * Execute the plugin command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output Console output.
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        (new Packman())->runCommand($input, $output);
    }
}
