<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;

/**
 * Package Manager
 * 
 * @see composer/src/Composer/Plugin/PluginInterface.php
 * @see function addInstaller at https://github.com/composer/composer/blob/4b4a3937eaa31c42dd905829fe1b04f4c94ab334/src/Composer/Installer/InstallationManager.php
 * @see InstallerInterface https://github.com/composer/composer/blob/4b8e77b2da9515f3462431dd5953e2560811263a/src/Composer/Installer/InstallerInterface.php
 */
class Loader implements PluginInterface, Capable
{
    const PROMPT = Packman::YELLOW_CIRCLE . ' ';

    private $packman;

    /**
     * @inheritDoc
     * Apply plugin modifications to Composer
     * 
     * The activate() method of the plugin is called after the plugin is loaded 
     * and receives an instance of Composer\Composer as well as an instance of 
     * Composer\IO\IOInterface. Using these two objects all configuration can be 
     * read and all internal objects and state can be manipulated as desired.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write(self::PROMPT . "Packman...", true);

        $this->packman = new Packman();

        $this->packman->startServer($composer);

        // $installer = new Installer($io, $composer);
        // $composer->getInstallationManager()->addInstaller($installer);
    }

    /**
     * @inheritDoc
     * Remove any hooks from Composer
     *
     * This will be called when a plugin is deactivated before being
     * uninstalled, but also before it gets upgraded to a new version
     * so the old one can be deactivated and the new one activated.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write(self::PROMPT . "Packman is done", true);
    }

    /**
     * @inheritDoc
     * Prepare the plugin to be uninstalled
     *
     * This will be called after deactivate.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write(self::PROMPT . "Uninstalling Packman...", true);
    }

    /**
     * @inheritDoc
     * Declare the capabilities of the plugin. This method must return an array, 
     * with the key as a Composer Capability class name, and the value as the
     * Plugin's own implementation class name of said Capability/
     *
     * @return void
     */
    public function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Proximify\ComposerPlugin\CommandProvider',
        ];
    }
}
