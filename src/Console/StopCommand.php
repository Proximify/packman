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

class StopCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('packman:stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        (new Packman())->runCommand('stop', $input, $output);
    }
}
