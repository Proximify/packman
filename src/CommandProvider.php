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
        return [new Command];
    }
}

/**
 * @link https://github.com/symfony/symfony/blob/5.x/src/Symfony/Component/Console/Output/OutputInterface.php
 */
class Command extends BaseCommand
{
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pm = new Packman();

        // $output->writeln('Executing ...');

        switch ($this->cmdName) {
            case 'packman-init':
                return $pm->init();
            case 'packman-update':
                return $pm->updateSatis();
        }
    }
}
