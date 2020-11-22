<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\Packman;

use Proximify\Packman\Satis;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
    const NAMESPACE_KEY = 'namespace';
    const REMOTE_DIR_KEY = 'remoteUrl';
    const LOCAL_URL_KEY = 'localUrl';
    const SYMLINK_DIR_KEY = 'symlinkDir';

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

    public function start(array $packages = [], array $options = [])
    {
        $satis = $this->newSatis();

        $reset = $options['reset'] ?? false;

        $satis->updateSatisFile($packages, $reset);

        $status = $satis->getStatus();

        if (!($status['needsBuild'] ?? false)) {
            return;
        }

        // Init the root dir and add it it to .gitignore
        $this->makeRootDir();

        $needsReset = $status['needsReset'] ?? false;
        $noWebServer = $options['noWebServer'] ?? false;

        // If no reset is needed, the server can be started right away
        $needsReset ? $this->stopServer() : $this->startServer();

        $satis->buildSatis();

        // If a reset was done, the server is started after it
        if ($needsReset) {
            $this->startServer();
        }

        $this->addSymlinkRepositories();
        $this->removeUnusedRepositories();
        $this->addLocalServerToComposer();

        // $repos = $this->getRepositories();

        // foreach ()
        // $this->log(, 'Repositories');
    }

    public function runCommand(
        string $name,
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->output = $output;

        switch ($name) {
            case 'list':
                return $this->listSatisRepos();
            case 'build':
                return $this->start(['noWebServer' => true]);
                // case 'purge':
                //     return $this->newSatis()->runSatisCommand('purge');
            case 'reset':
                return $this->start(['reset' => true]);
        }
    }

    public function listSatisRepos()
    {
        // print_r($this->getDeclaredRepos());
    }

    public function addPackages(array $packages)
    {
        // The start() method was supposedly already called, so only
        // call it again if there are new packages
        if ($packages) {
            $this->start($packages);
        }
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

        $target = $this->getSatisDir();

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

    protected function writeMsg(string $msg, $level = self::NORMAL)
    {
        if (!is_string($msg)) {
            $msg = print_r($msg, true);
        }

        $msg = self::prefix($msg);

        self::$io ? self::$io->write($msg, true, $level) : print("$msg\n");
    }

    protected function writeError(string $msg, $level = self::NORMAL)
    {
        $msg = self::prefix($msg, true);

        self::$io ? self::$io->writeError($msg, $level) : print("$msg\n");
    }

    protected function log($msg, ?string $lbl = null)
    {
        if (!is_string($msg)) {
            $msg = print_r($msg, true);
        }

        if ($lbl) {
            $msg = "$lbl:\n" . $msg;
        }

        $this->writeMsg($msg, self::VERBOSE);
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

    private function newSatis()
    {
        $vendor = $this->getVendorName();

        $options = [
            'binPath' => $this->getSatisBinaryPath(),
            'rootDir' => $this->getPackmanDir(),
            'localUrl' => $this->getLocalUrl(),
            'vendor' => $vendor,
            'remoteUrl' => $this->getRemoteUrl($vendor),
            'exclude' => $this->getPublicPackages($vendor),
            'require' => $this->getRequiredPackages(),
        ];

        return new Satis($options);
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
        $satis = $this->satisConfig['require'] ?? false;

        if (!$satis) {
            return;
        }

        $symlinkDir = $this->getConfigValue(self::SYMLINK_DIR_KEY);
        $home = getenv('HOME');

        if ($symlinkDir && $symlinkDir[0] == '~' && $home) {
            $symlinkDir = $home . '/' . substr($symlinkDir, 1);
        }

        $symlinkDir = realpath($symlinkDir);
        $this->log($symlinkDir, 'sym');

        if (!$symlinkDir || !is_dir($symlinkDir)) {
            return;
        }

        $dir = new \DirectoryIterator($symlinkDir);
        $own = [];

        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->isDir()) {
                $dir = $fileinfo->getPathname();
                $config = self::readJsonFile("$dir/composer.json");

                if (($name = $config['name'] ?? false) && isset($satis[$name])) {
                    $own[] = $fileinfo->getFilename();
                    $this->addSymlinkRepo($fileinfo->getFilename());
                }
            }
        }

        $this->log($own, 'own');
        // $this->log($satis, 'satis');
    }

    private function removeUnusedRepositories()
    {
        // Read the packages.json pf satis to see what is
        // actually downloaded
        $repos = $this->getSatisRepoDependencies();

        $this->log($repos, 'active');
    }

    private function addLocalServerToComposer()
    {
        // Disable the secure HTTP restriction (or 'disable-tls' => false)
        $this->setComposerConfig('secure-http', false);

        $config = [
            'url' => $this->satisConfig['homepage']
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

        $rm->addRepository($repo);
    }

    private function getRepositories(): array
    {
        $rm = self::$composer->getRepositoryManager();
        return $rm->getRepositories();
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

    private function makeRootDir()
    {
        $dir = $this->getPackmanDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $this->addToGitIgnore($dir);
        }
    }

    private function addToGitIgnore(string $dir): void
    {
        $filename = '.gitignore';

        if (file_exists($filename)) {
            // Read entire file into an array
            foreach (file($filename) as $line) {
                if (trim($line) == $dir) {
                    return;
                }
            }
        }

        // Write the contents to the file using the FILE_APPEND flag to append the
        // content to the end of the file and the LOCK_EX flag to prevent anyone
        // else writing to the file at the same time
        file_put_contents($filename, "\n$dir\n", FILE_APPEND | LOCK_EX);
    }

    private function setComposerConfig(string $key, $value): void
    {
        $config = [
            'config' => [$key => $value]
        ];

        self::$composer->getConfig()->merge($config);
    }

    private static function getGlobalComposer()
    {
        return self::$composer->getPluginManager()->getGlobalComposer();
    }

    private function getConfigValue(string $key)
    {
        // Highest priority config values are set first
        $merged = ($key == self::PACKMAN_DIR_KEY) ? [] :
            $this->readJsonFile($this->getPackmanFilename());

        $local = self::$composer->getPackage()->getExtra();
        $global = self::getGlobalComposer()->getPackage()->getExtra();

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
        $namespace = $this->getConfigValue(self::NAMESPACE_KEY);

        if ($namespace) {
            return $namespace;
        }

        $name = self::$composer->getPackage()->getName();

        $pos = strpos($name, '/');

        if (!$name || !$pos) {
            $this->writeError("Cannot find namespace in composer.json");
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

    private static function trimAll($str, $what = null, $with = ' ')
    {
        if ($what === null) {
            //  Character      Decimal      Use
            //  "\0"            0           Null Character
            //  "\t"            9           Tab
            //  "\n"           10           New line
            //  "\x0B"         11           Vertical Tab
            //  "\r"           13           New Line in Mac
            //  " "            32           Space

            $what   = "\\x00-\\x20";    //all white-spaces and control chars
        }

        return trim(trim(preg_replace("/[" . $what . "]+/", $with, $str), $what));
    }

    private static function prefix(string $msg, $isError = false)
    {
        $icon = $isError ? self::RED_CIRCLE : self::YELLOW_CIRCLE;

        return $icon . ' Packman: ' . $msg;
    }

    private static function cleanSatisError(string $msg, $level): string
    {
        if ($level == self::VERBOSE) {
            return $msg;
        }

        // Remove the <warning></warning> tag
        $msg = strip_tags($msg);

        $annoying = 'The "proximify/packman" plugin was skipped because it requires a Plugin API version ("^2.0") that does not match your Composer installation ("1.1.0"). You may need to run composer update with the "--no-plugins" option.';

        $msg = str_replace($annoying, '', $msg);

        return self::trimAll($msg);
    }
}
