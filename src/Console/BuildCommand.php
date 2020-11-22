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

class BuildCommand extends BaseCommand
{
    public function __construct()
    {
        // Do this BEFORE calling the parent constructor because the base
        // constructor calls configure().
        // $this->cmdName = $name;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('packman:build');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        (new Packman())->runCommand('build', $input, $output);
    }
}
