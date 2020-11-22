<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\Packman;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Package\Version\VersionParser;

/**
 * Package Manager
 *
 * @see composer/src/Composer/Plugin/PluginInterface.php
 * @see function addInstaller at src/Composer/Installer/InstallationManager.php
 * @see InstallerInterface src/Composer/Installer/InstallerInterface.php
 */
class Loader implements PluginInterface, Capable, EventSubscriberInterface
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
        // $installer = new Installer($io, $composer);
        // $composer->getInstallationManager()->addInstaller($installer);

        ($this->packman = new Packman($composer, $io))->start();
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
            'Composer\Plugin\Capability\CommandProvider' => 'Proximify\Packman\Console\CommandProvider',
        ];
    }

    /**
     * Undocumented function
     *
     * @link https://getcomposer.org/doc/articles/plugins.md#event-handler
     * @link https://getcomposer.org/doc/articles/scripts.md#event-names
     * @link src/Composer/Command/RequireCommand.php LINE 291
     *
     * @return void
     */
    public static function getSubscribedEvents()
    {
        return [
            // 'init' => 'initCommand',
            'pre-command-run' => 'preCommandRun', // src/Composer/Plugin/PreCommandRunEvent.php
            // 'pre-file-download' => 'preFileDownload'
        ];
    }

    public static function needsWebServer($event): bool
    {
        $commands = ['install', 'update', 'require'];
        $cmd = $event->getCommand();

        return in_array($cmd, $commands);
    }

    public function preCommandRun(PreCommandRunEvent $event)
    {
        if (!self::needsWebServer($event)) {
            return;
        }

        $input = $event->getInput();
        $require = [];

        if ($input->hasArgument('packages')) {
            $packages = $input->getArgument('packages');

            $parser = new VersionParser();

            $parsed = $parser->parseNameVersionPairs($packages);

            foreach ($parsed as $pkg) {
                // Note: there might be multiple ones with the same name
                // and different version
                $require[$pkg['name']] = $pkg['version'] ?? 0;
            }

            $this->packman->addPackages($require);
        }

        $this->packman->start($require);
    }

    // public function initCommand($event)
    // {
    //     $this->packman->start();
    // }

    // public function preFileDownload(PreFileDownloadEvent $event)
    // {
    //     $name = $event->getName();
    // }
}
