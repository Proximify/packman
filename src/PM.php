<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Package Manager
 * 
 * @see composer/src/Composer/Plugin/PluginInterface.php
 */
class PM implements PluginInterface
{
    /**
     * @inheritDoc
     * Apply plugin modifications to Composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        echo "\nACTIVATE\n";
        // $installer = new TemplateInstaller($io, $composer);
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
    }
}
