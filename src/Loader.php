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
        $io->write(self::PROMPT . "Packman...", true);

        // $installer = new Installer($io, $composer);
        // $composer->getInstallationManager()->addInstaller($installer);
        // getInstalledPackages()

        $this->packman = new Packman();

        $this->packman->start($composer);
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

    /**
     * Undocumented function
     *
     * @link https://getcomposer.org/doc/articles/plugins.md#event-handler
     * @link https://getcomposer.org/doc/articles/scripts.md#event-names
     * 
     * @return void
     */
    public static function getSubscribedEvents()
    {
        //'require'
        // see src/Composer/Command/RequireCommand.php LINE 291

        return [
            // 'init' => 'initCommand',
            'pre-command-run' => 'preCommandRun', // src/Composer/Plugin/PreCommandRunEvent.php
            // 'pre-file-download' => 'preFileDownload'
        ];
    }

    public function preCommandRun(PreCommandRunEvent $event)
    {
        $name = $event->getName();
        $input = $event->getInput();
        $cmd = $event->getCommand(); // 'require'

        if ($input->hasArgument('packages')) {
            $packages = $input->getArgument('packages');

            $parser = new VersionParser();

            $parsed = $parser->parseNameVersionPairs($packages);

            $require = [];

            foreach ($parsed as $pkg) {
                // Note: there might be multiple ones with the same name
                // and different version
                $require[$pkg['name']] = $pkg['version'];
            }

            $this->packman->addPackages($require);
        }
    }

    // public function initCommand($event)
    // {
    //     // $event->getComposer();
    //     $name = $event->getName();
    //     $args = $event->getArguments();

    //     print_r("\nEvent init name: $name\n");
    //     print_r("\nArguments:\n");
    //     print_r(get_class($event));
    //     print_r("\n");
    // }

    // public function preFileDownload(PreFileDownloadEvent $event)
    // {
    //     $name = $event->getName();

    //     // https://repo.packagist.org/p2/proximify/glot-renderer.json
    //     // https://repo.packagist.org/p2/proximify/glot-renderer~dev.json
    //     // http://localhost:8081/packages.json
    //     print_r("\nEvent pre run name: $name\n");
    //     print_r("\nArguments:\n");
    //     print_r([$event->getProcessedUrl()]);
    //     print_r("\n");
    // }
}
