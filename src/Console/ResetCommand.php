<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin\Console;

use Proximify\ComposerPlugin\Packman;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class ResetCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('packman:reset');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        (new Packman())->runCommand('reset', $input, $output);
    }
}
