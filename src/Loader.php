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

/**
 * Package Manager
 * 
 * @see composer/src/Composer/Plugin/PluginInterface.php
 * @see function addInstaller at https://github.com/composer/composer/blob/4b4a3937eaa31c42dd905829fe1b04f4c94ab334/src/Composer/Installer/InstallationManager.php
 * @see InstallerInterface https://github.com/composer/composer/blob/4b8e77b2da9515f3462431dd5953e2560811263a/src/Composer/Installer/InstallerInterface.php
 */
class Loader implements PluginInterface
{
    private $handle;
    private $domain;

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
        $this->startServer();

        echo "\nACTIVATE\n";
        $installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
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
        echo "\nDE-ACTIVATE\n";
        $this->stopServer();
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
        echo "\nUN-INSTALL\n";
    }

    function startServer($port = '8081', $target = 'public')
    {
        if ($this->handle) {
            return $this->handle;
        }

        $this->domain = "localhost:$port";
        $this->handle = proc_open("php -S $this->domain -t '$target'", [], $pipes);

        return $this->handle;
    }

    function stopServer()
    {
        if ($this->handle) {
            echo 'CLOSING';
            proc_close($this->handle);
            $this->handle = null;
        }
    }
}
