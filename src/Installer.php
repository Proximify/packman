<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin;

use Composer\Installer\InstallerInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;

/**
 * Implementation of the interface for the package installation manager.
 * 
 * @see src/Composer/Installer/InstallerInterface.php
 * @see src/Composer/Installer/InstallationManager.php
 */
class Installer implements InstallerInterface
{
    /**
     * Decides if the installer supports the given type
     *
     * @param  string $packageType
     * @return bool
     */
    public function supports($packageType)
    {
        echo "TYPE:$packageType";
        return false;
    }

    /**
     * Checks that provided package is installed.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     *
     * @return bool
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        echo "isInstalled";
        return false;
    }

    /**
     * Downloads the files needed to later install the given package.
     *
     * @param  PackageInterface      $package     package instance
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface|null
     */
    public function download(PackageInterface $package, PackageInterface $prevPackage = null)
    {
        echo "download";
        return null;
    }

    /**
     * Do anything that needs to be done between all downloads have been 
     * completed and the actual operation is executed
     *
     * All packages get first downloaded, then all together prepared, then all 
     * together installed/updated/uninstalled. Therefore
     * for error recovery it is important to avoid failing during 
     * install/update/uninstall as much as possible, and risky things or
     * user prompts should happen in the prepare step rather. In case of failure, 
     * cleanup() will be called so that changes can
     * be undone as much as possible.
     *
     * @param  string                $type        one of install/update/uninstall
     * @param  PackageInterface      $package     package instance
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface|null
     */
    public function prepare(
        $type,
        PackageInterface $package,
        PackageInterface $prevPackage = null
    ) {
        echo "prepare";
        return null;
    }

    /**
     * Installs specific package.
     *
     * @param  InstalledRepositoryInterface $repo    repository in which to check
     * @param  PackageInterface             $package package instance
     * @return PromiseInterface|null
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        echo "install";
        return null;
    }

    /**
     * Updates specific package.
     *
     * @param  InstalledRepositoryInterface $repo    repository in which to check
     * @param  PackageInterface             $initial already installed package version
     * @param  PackageInterface             $target  updated version
     * @return PromiseInterface|null
     *
     * @throws InvalidArgumentException if $initial package is not installed
     */
    public function update(
        InstalledRepositoryInterface $repo,
        PackageInterface $initial,
        PackageInterface $target
    ) {
        echo "update";
        return null;
    }

    /**
     * Uninstalls specific package.
     *
     * @param  InstalledRepositoryInterface $repo    repository in which to check
     * @param  PackageInterface             $package package instance
     * @return PromiseInterface|null
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        echo "uninstall";
        return null;
    }

    /**
     * Do anything to cleanup changes applied in the prepare or install/update/uninstall steps
     *
     * Note that cleanup will be called for all packages regardless if they 
     * failed an operation or not, to give
     * all installers a change to cleanup things they did previously, so you 
     * need to keep track of changes
     * applied in the installer/downloader themselves.
     *
     * @param  string                $type        one of install/update/uninstall
     * @param  PackageInterface      $package     package instance
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface|null
     */
    public function cleanup(
        $type,
        PackageInterface $package,
        PackageInterface $prevPackage = null
    ) {
        echo "cleanup";
        return null;
    }

    /**
     * Returns the installation path of a package
     *
     * @param  PackageInterface $package
     * @return string           path to install to, which MUST not end with a slash
     */
    public function getInstallPath(PackageInterface $package)
    {
        echo "getInstallPath";
        return null;
    }
}
