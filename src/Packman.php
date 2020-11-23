<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\Packman;

use Proximify\Packman\Satis;
use Composer\Composer;
use Composer\IO\IOInterface;

defined('JSON_THROW_ON_ERROR') or define('JSON_THROW_ON_ERROR', 0);

/**
 * Package Manager
 */
class Packman
{
    const YELLOW_CIRCLE = "\u{1F7E1}";
    const RED_CIRCLE = "\u{1F534}";
    const SEPARATOR = '------------';

    const NORMAL = IOInterface::NORMAL;
    const VERBOSE = IOInterface::VERBOSE;

    const PACKMAN_DIR_KEY = 'packmanDir';
    const VENDOR_KEY = 'vendor';
    const REMOTE_DIR_KEY = 'remoteUrl';
    const LOCAL_URL_KEY = 'localUrl';
    const SYMLINK_DIR_KEY = 'symlinkDir';
    const SYMLINKS_KEY = 'symlinks';

    /**
     * @var int Counter of Packman current instances. Used to remove
     * static assets.
     */
    private static $instanceCount = 0;

    /** @var mixed Handle for a web server process. */
    private static $serverHandle;

    /** @var mixed Original composer object provided during plugin load. */
    private static $composer;

    /** @var mixed The IO object of composer provided at load time. */
    private static $io;

    /** @var Satis The satis object used by Packman. Constructed when needed. */
    private $satis;

    public function __construct(?Composer $composer = null, ?IOInterface $io = null)
    {
        self::$instanceCount++;
        if ($composer) {
            self::$composer = $composer;
        }

        if ($io) {
            self::$io = $io;
        }
    }

    public function __destruct()
    {
        self::$instanceCount--;

        if (self::$instanceCount == 0) {
            $this->stopServer();
            self::$composer = null;
            self::$io = null;
        }
    }

    public function runCommand(string $name, array $options = [])
    {
        switch ($name) {
            case 'list':
                return $this->listSatisRepos();
            case 'build':
                return $this->start(['webServer' => false]);
            case 'purge':
                return $this->getSatis()->purge();
            case 'reset':
                return $this->start(['reset' => true]);
            case 'start':
                $options = ['skipBuild' => true, 'webServer' => false];
                return $this->start($options);
            case 'stop':
                return $this->stopServer();
        }
    }

    public function start(array $options = [])
    {
        $satis = $this->getSatis();

        if (!$satis) {
            return;
        }

        $reset = $options['reset'] ?? false;
        $packages = $options['packages'] ?? [];

        // Update satis.json based on the current requirements
        // in composer.json given to the Satis constructor plus
        // the additional packages received in the arguments.
        $satis->updateSatisFile($packages, $reset);

        $status = $satis->getStatus();

        $needsBuild = $status['needsBuild'] ?? false;
        $needsReset = $status['needsReset'] ?? false;

        $webServer = $options['webServer'] ?? true;
        $skipBuild = $options['skipBuild'] ?? false;

        if (!$needsBuild) {
            return;
        }

        if ($webServer) {
            // If no reset is needed, the server can be started right away
            $needsReset ? $this->stopServer() : $this->startServer();
        }

        if (!$skipBuild) {
            $satis->buildSatis();
        }

        // If a reset was done, the server is started after it
        if ($needsReset && $webServer) {
            $this->startServer();
        }

        $this->addSymlinkRepositories();
        // $this->removeUnusedRepositories();
        $this->addLocalServerToComposer();

        // $this->log(array_keys($this->getRepositories()), 'Active repos');
    }

    public function listSatisRepos()
    {
        // print_r($this->getDeclaredRepos());
    }

    public function stopServer()
    {
        if (self::$serverHandle) {
            proc_terminate(self::$serverHandle);
            self::$serverHandle = null;
            $this->log("Server stopped");
        }
    }

    /**
     * Start the web server.
     *
     * @return void
     */
    public function startServer(): void
    {
        if (self::$serverHandle) {
            return;
        }

        $target = $this->getSatis()->getOutputDir();

        if (!is_dir($target)) {
            return;
        }

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            $cb = [$this, 'stopServer'];
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, $cb);
            pcntl_signal(SIGTERM, $cb);
            pcntl_signal(SIGHUP, $cb);
        }

        // Default in case the URL is missing parts.
        $host = $this->getHostUrl();

        $this->writeMsg("Starting web server...");

        $cmd = "php -S '$host' -t '$target'";

        self::$serverHandle = proc_open($cmd, [], $pipes);
    }

    public static function writeMsg(string $msg, $level = self::NORMAL)
    {
        if (!is_string($msg)) {
            $msg = print_r($msg, true);
        }

        $msg = self::prefix($msg);

        self::$io ? self::$io->write($msg, true, $level) : print("$msg\n");
    }

    public static function writeError(string $msg, $level = self::NORMAL)
    {
        $msg = self::prefix($msg, true);

        self::$io ? self::$io->writeError($msg, $level) : print("$msg\n");
    }

    public static function log($msg, ?string $lbl = null)
    {
        if (!is_string($msg)) {
            $msg = print_r($msg, true);
        }

        if ($lbl) {
            $msg = "$lbl:\n" . $msg;
        }

        self::writeMsg($msg, self::VERBOSE);
    }

    public static function prefix(string $msg, $isError = false)
    {
        $icon = $isError ? self::RED_CIRCLE : self::YELLOW_CIRCLE;

        return $icon . ' Packman: ' . $msg;
    }

    public static function getRealPath(string $path): ?string
    {
        // Convert the ~ prefix to $home, which is what the shell does
        if ($path && $path[0] == '~') {
            if ($home = getenv('HOME')) {
                $path = $home . '/' . substr($path, 1);
            }
        }

        return realpath($path);
    }

    /**
     * Remove the vendor name from the given repository names.
     */
    public static function removeVendor(string $vendor, string $name): ?string
    {
        $vendor .= '/';
        $len = strlen($vendor);

        return (strncmp($name, $vendor, $len) === 0) ?
            substr($name, $len) : null;
    }

    /**
     * Get contents of remote URL.
     *
     * @param string   $fileUrl   The file URL
     * @param resource $context   The stream context
     *
     * @return string|false The response contents or false on failure
     */
    protected function getRemoteContents($fileUrl)
    {
        $result = file_get_contents($fileUrl, false);

        // $responseHeaders = $http_response_header ?? [];

        return $result;
    }

    private function getSatis(): ?Satis
    {
        if ($this->satis) {
            return $this->satis;
        }

        $vendor = $this->getVendorName();

        if (!$vendor) {
            return null;
        }

        $options = [
            'binPath' => $this->getSatisBinaryPath(),
            'rootDir' => $this->getPackmanDir(),
            'localUrl' => $this->getLocalUrl(),
            'vendor' => $vendor,
            'remoteUrl' => $this->getRemoteUrl($vendor),
            'exclude' => $this->getPublicPackages($vendor),
            'require' => $this->getRequiredPackages(),
        ];

        return $this->satis = new Satis($options);
    }

    private function getSatisBinaryPath(): ?string
    {
        $satisPath = self::$composer->getConfig()->get('bin-dir') . '/satis';

        if (is_file($satisPath)) {
            return $satisPath;
        }

        // Try using the global bin-dir
        $pm = self::$composer->getPluginManager();
        $globalComposer = $pm->getGlobalComposer();

        $satisPath = $globalComposer->getConfig()->get('bin-dir') . '/satis';

        return is_file($satisPath) ? $satisPath : null;
    }

    private function addSymlinkRepositories()
    {
        // The set of private repositories is the one that
        // is kept in the satis.json file
        // $satis = $this->satisConfig['require'] ?? false;

        // if (!$satis) {
        //     return;
        // }

        $symlinkDir = $this->getSymlinkDir();
        $symlinks = $this->getConfigValue(self::SYMLINKS_KEY);

        // $this->log($symlinkDir, 'Symlink directory');

        if (!$symlinks || !$symlinkDir || !is_dir($symlinkDir)) {
            return;
        }

        $dir = new \DirectoryIterator($symlinkDir);
        $vendor = $this->getVendorName();

        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isDir()) {
                continue;
            }

            $dir = $fileinfo->getPathname();
            $config = self::readJsonFile("$dir/composer.json");
            $name = $config['name'] ?? false;

            if (!$name || !($name = self::removeVendor($vendor, $name))) {
                continue;
            }

            // $this->writeMsg($name);
            // isset($satis[$name])

            if (in_array($name, $symlinks)) {
                $this->addSymlinkRepo($fileinfo->getPathname());
            }
        }
    }

    private function removeUnusedRepositories()
    {
        // Read the packages.json pf satis to see what is
        // actually downloaded
        $repos = $this->getSatis()->getDependencies();

        $this->log($repos, 'active');
    }

    private function addLocalServerToComposer()
    {
        // Disable the secure HTTP restriction (or 'disable-tls' => false)
        $this->setComposerConfig('secure-http', false);

        $config = [
            'url' => $this->getLocalUrl()
        ];

        $this->addRepository('composer', $config);
    }

    private function addSymlinkRepo(string $path)
    {
        $config = [
            'url' => $path,
            'options' => [
                'symlink' => true
            ]
        ];

        $this->addRepository('path', $config);
    }

    private function addRepository(string $type, $config): void
    {
        $rm = self::$composer->getRepositoryManager();

        $repo = $rm->createRepository($type, $config);

        // $this->log($repo->getRepoName(), 'Adding Symlink repo...');

        $rm->addRepository($repo);
    }

    /**
     * Get the current repositories in the composer object. 
     * Note that duplicates are avoided by qualifying the repository names
     * with their type and URL. For example,
     * 
     * - "composer repo (https://repo.packagist.org)"
     * - "path repo (/.../vendor/repo-name)"
     * - "composer repo (http://localhost:8081)"
     * 
     * The 'composer' type has class: Composer\Repository\ComposerRepository
     * The 'path' type has class: Composer\Repository\PathRepository
     * 
     * @return array
     */
    private function getRepositories(): array
    {
        $rm = self::$composer->getRepositoryManager();
        $repos = [];

        foreach ($rm->getRepositories() as $repo) {
            $repos[$repo->getRepoName()] = $repo;
        }

        return $repos;
    }

    private static function readJsonFile(string $filename)
    {
        if (!is_file($filename)) {
            return [];
        }

        $json = file_get_contents($filename);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?: [];
    }

    private function getPublicPackages(string $vendor)
    {
        $url = 'https://packagist.org/packages/list.json?vendor=' . $vendor;

        $response = json_decode($this->getRemoteContents($url, []), true);

        return $response['packageNames'] ?? [];
    }

    private function setComposerConfig(string $key, $value): void
    {
        $config = [
            'config' => [$key => $value]
        ];

        self::$composer->getConfig()->merge($config);
    }

    private static function getGlobalComposer(): ?Composer
    {
        return self::$composer->getPluginManager()->getGlobalComposer();
    }

    private function getConfigValue(string $key)
    {
        // Highest priority config values are set first
        $merged = ($key == self::PACKMAN_DIR_KEY) ? [] :
            $this->readJsonFile($this->getPackmanFilename());

        $globalComposer = self::getGlobalComposer();

        $local = self::$composer->getPackage()->getExtra();

        $global = $globalComposer ?
            $globalComposer->getPackage()->getExtra() : [];

        $merged += ($local['packman'] ?? []) + ($global['packman'] ?? []);

        return $merged[$key] ?? null;
    }

    private function getRequiredPackages(): array
    {
        $pkg = self::$composer->getPackage();

        return $pkg->getRequires() + $pkg->getDevRequires();
    }

    private function getVendorName(): ?string
    {
        $vendor = $this->getConfigValue(self::VENDOR_KEY);

        if ($vendor) {
            return $vendor;
        }

        $name = self::$composer->getPackage()->getName();

        $pos = strpos($name, '/');

        if (!$name || !$pos) {
            $this->writeError("Cannot find vendor name in composer.json");
            return null;
        }

        return substr($name, 0, $pos);
    }

    private function getRemoteUrl(string $vendor): string
    {
        $url = $this->getConfigValue(self::REMOTE_DIR_KEY);

        return $url ?: "https://github.com/$vendor/";
    }

    private function getLocalUrl(): string
    {
        $url = $this->getConfigValue(self::LOCAL_URL_KEY);

        return $url ?: 'http://localhost:8081';
    }

    private function getHostUrl(): string
    {
        // Remove the protocol
        $url = parse_url($this->getLocalUrl());

        $host = $url['host'] ?? 'localhost';

        if ($port = $url['port'] ?? false) {
            $host .= ":$port";
        }

        return $host;
    }

    private function getPackmanDir(): string
    {
        $dir = $this->getConfigValue(self::PACKMAN_DIR_KEY);

        return $dir ?: 'packman';
    }

    private function getPackmanFilename(): string
    {
        return $this->getPackmanDir() . '/packman.json';
    }

    private function getSymlinkDir(): ?string
    {
        $path = $this->getConfigValue(self::SYMLINK_DIR_KEY);

        return $path ? self::getRealPath($path) : null;
    }
}
