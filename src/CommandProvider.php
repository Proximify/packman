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

class Command extends BaseCommand
{
    protected function configure()
    {
        $this->setName('get-repos');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pm = new PM();

        $output->writeln('Executing get-repos...');

        $pm->updateSatis();
    }
}
