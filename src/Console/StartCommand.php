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

class StartCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('packman-start');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        (new Packman())->runCommand('start', $input, $output);
    }
}
