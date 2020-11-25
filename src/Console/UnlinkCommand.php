<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\Packman\Console;

use Proximify\Packman\Packman;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class UnlinkCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('packman-unlink');

        $this->setDescription('Remove existing symlinks to the given repository folders');

        $this->addArgument('folders', InputArgument::IS_ARRAY, 'Folders names');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $options = [];

        if ($input->hasArgument('folders')) {
            $options['folders'] = $input->getArgument('folders');
            (new Packman())->runCommand('unlink', $options);
        }
    }
}
