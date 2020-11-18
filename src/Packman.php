<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin;

use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Composer;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\InstalledVersions;

/**
 * Package Manager
 *
 * Note: Get RootPackage with $composer->getPackage()->getMinimumStability()
 *
 * @see findBestVersionAndNameForPackage in src/Composer/Command/InitCommand.php
 * $this->normalizeRequirements($requires);
 * @see src/Composer/InstalledVersions.php
 * @see src/Composer/Factory.php [has static methods]
 */
class Packman
{
    const YELLOW_CIRCLE = "\u{1F7E1}";
    const RED_CIRCLE = "\u{1F534}";
    // const GREEN_CIRCLE = "\u{1F7E2}";
    // const PACKMAN_ICON = "\u{25D4}";
    const OUTPUT_DIR = 'private-packages/repos';
    const SATIS_FILE = 'private-packages/satis.json';

    const VERBOSE = IOInterface::VERBOSE;

    /**
     * @var int Counter of Packman current instances. Used to remove
     * static assets.
     */
    private static $instanceCount = 0;

    /** @var mixed Handle for a web server process. */
    private static $handle;
    private static $composer;
    private static $io;

    private $output;
    private $settings;
    private $localUrl;
    private $remoteUrl;
    private $namespace;
    private $satisConfig;
    private $versions;

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

    public static function setComposer(Composer $composer)
    {
        self::$composer = $composer;
    }

    public function start(array $packages = [])
    {
        $this->writeMsg("Packman...", self::VERBOSE);

        // Always read the composer file to init properties
        if (!$this->readComposerFile())
            return;

        $changed = $this->updateSatisFile($packages);

        // Check if the satis file defines a homepage and requirements
        if (!$this->needsServer()) {
            return;
        }

        $this->startServer();

        $this->updateSatis($changed);

        $this->addLocalServerToComposer();
    }

    public function runCommand(InputInterface $input, OutputInterface $output)
    {
        $command = $input->getOption('command');
        $this->output = $output;

        // Read the composer file to init properties
        if (!$this->readComposerFile())
            return;

        switch ($command) {
            case 'packman-init':
            case 'init-packman':
                return $this->initComposerFile();
            case 'packman-update':
            case 'update-packman':
                return $this->updateSatis();
        }
    }

    public function needsServer()
    {
        $url = $this->satisConfig['homepage'] ?? false;
        $require = $this->satisConfig['require'] ?? false;

        return ($url && $require);
    }

    public function checkComposerFile()
    {
        $homepage = strtolower($this->satisConfig['homepage']);
        $repos = $this->settings['repositories'] ?? [];

        foreach ($repos as $repo) {
            $type = strtolower($repo['type'] ?? '');
            $url = strtolower($repo['url'] ?? '');

            if ($type == 'composer' && $url == $homepage) {
                return true;
            }
        }

        return false;
    }

    public function initComposerFile()
    {
    }

    public function updateSatis(bool $changed = false)
    {
        $options = [];

        $ourDir = self::OUTPUT_DIR;
        $configPath = self::SATIS_FILE;

        $cmd = "php vendor/bin/satis build '$configPath' '$ourDir'";

        if (empty($this->options['interactive'])) {
            // -n (or --no-interaction) is used to use the ssh key of the
            // machine instead of asking for a token.
            $cmd .= ' -n';
        } else {
            // Show the request coming from the STDERR
            $options['stderr'] = fopen('php://stderr', 'w');
        }

        if ($changed && is_dir($ourDir)) {
            // $cmd = "rm -rf '$ourDir' && $cmd";
            $msg = "Recomputing all private packages...";
        } else {
            $msg = "Refreshing private packages...";
        }

        $this->writeMsg($msg);

        $status = self::execute($cmd, $options);
        $code = $status['code'];

        if ($status['out']) {
            $this->writeMsg($status['out'], self::VERBOSE);
        }

        if ($status['err']) {
            $this->writeError($status['err']);
        }

        $this->writeMsg("Packages update complete", self::VERBOSE);
    }

    public function addPackages(array $packages)
    {
        if ($packages) {
            $this->start($packages);
        }
    }

    public function stopServer()
    {
        if (self::$handle) {
            proc_terminate(self::$handle);
            self::$handle = null;
            $this->writeMsg("Server stopped", self::VERBOSE);
        }
    }

    /**
     * Start the web server.
     *
     * @return void
     */
    protected function startServer()
    {
        $target = self::OUTPUT_DIR;

        if (self::$handle || !is_dir($target)) {
            return;
        }

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            $cb = [$this, 'stopServer'];
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, $cb);
            pcntl_signal(SIGTERM, $cb);
            pcntl_signal(SIGHUP, $cb);
        }

        $url = parse_url($this->localUrl);

        // Default in case the URL is missing parts. The actual default
        // localUrl is set by readComposerFile()
        $host = $url['host'] ?? 'localhost';

        if ($port = $url['port'] ?? false) {
            $host .= ":$port";
        }

        $this->writeMsg("Creating web server at $host from $target...", self::VERBOSE);

        $cmd = "php -S '$host' -t '$target'";

        self::$handle = proc_open($cmd, [], $pipes);
    }

    protected function readComposerFile(): bool
    {
        // Use $json = file_get_contents(Factory::getComposerFile()); ???
        $this->settings = self::readJsonFile('composer.json');

        $config = $this->settings['extras']['packman'] ?? [];

        if (!($this->localUrl = $config['localUrl'] ?? false)) {
            $this->localUrl = 'http://localhost:8081';
        }

        if (!($this->remoteUrl = $config['remoteUrl'] ?? false)) {
            $this->remoteUrl = 'https://github.com/';
        }

        if (!($this->namespace = $config['namespace'] ?? false)) {
            $name = $this->settings['name'] ?? '';
            $pos = strpos($name, '/');

            if (!$name || !$pos) {
                $this->writeError("Cannot find namespace in composer.json");
                return false;
            }

            $this->namespace = substr($name, 0, $pos);
        }

        $installed = InstalledVersions::getInstalledPackages();
        $versions = [];

        foreach ($installed as $name) {
            $versions[$name] = InstalledVersions::getVersion($name);
        }

        $this->versions = $versions;

        return true;
    }

    protected static function execute(string $cmd, array $options = []): array
    {
        $cwd = $options['cwd'] ?? null;
        $env = $options['env'] ?? null;
        $errPipe = $options['stderr'] ?? null;

        $descriptor = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => $errPipe ?: ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptor, $pipes, $cwd, $env);

        if (!$process) {
            throw new Exception(self::prefix("Cannot execute command"));
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);

        // Only close the pipe of it was not given
        if (!$errPipe) {
            fclose($pipes[2]);
        }

        return [
            'out' => trim($stdout),
            'err' => trim($stderr),
            'code' => proc_close($process)
        ];
    }

    protected static function prefix(string $msg, $isError = false)
    {
        $icon = $isError ? self::RED_CIRCLE : self::YELLOW_CIRCLE;

        return $icon . ' Packman: ' . $msg;
    }

    protected function writeMsg(string $msg, $level = IOInterface::NORMAL)
    {
        $msg = self::prefix($msg);

        self::$io ? self::$io->write($msg, true, $level) : print("$msg\n");
    }

    protected function writeError(string $msg)
    {
        $msg = self::prefix($msg, true);

        self::$io ? self::$io->writeError($msg) : print("$msg\n");
    }

    private function addLocalServerToComposer()
    {
        $config = self::$composer->getConfig();

        $config->merge(['secure-http' => false]);

        // $secureHttp = $config->get('secure-http');

        $rm = self::$composer->getRepositoryManager();

        $repoConfig = [
            'url' => $this->satisConfig['homepage']
        ];

        $repo = $rm->createRepository('composer', $repoConfig);

        $rm->addRepository($repo);
    }

    private static function readJsonFile(string $filename)
    {
        if (!is_file($filename)) {
            return [];
        }

        $json = file_get_contents($filename);

        return json_decode($json, true) ?? [];
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function saveJsonFile(string $filename, array $data)
    {
        file_put_contents($filename, self::encode($data));
    }

    private function getDeclaredRepos(array $newPackages = []): array
    {
        $packages = ($this->settings['require'] ?? []) +
            ($this->settings['require-dev'] ?? []) + $newPackages;

        // Remove self from the array
        unset($packages['proximify/packman']);

        $needle = $this->namespace . '/';
        $targets = [];

        foreach (array_keys($packages) as $key) {
            $len = strlen($needle);
            if (strncmp($key, $needle, $len) === 0) {
                $targets[] = substr($key, $len);
            }
        }

        return $targets;
    }

    private function getDefaultSatisConfig(): array
    {
        return [
            'name' => 'proximify/packman-satis',
            'require-dependencies' => false,
            'archive' => [
                'directory' => 'dist',
                'format' => 'tar',
                'skip-dev' => false
            ]
        ];
    }

    private function addGitIgnore(string $dir)
    {
        $filename = '.gitignore';

        if (file_exists($filename)) {
            // Read entire file into an array
            foreach (file($filename) as $line) {
                if (trim($line) == $dir)
                    return;
            }
        }

        // Write the contents to the file,
        // using the FILE_APPEND flag to append the content to the end of the file
        // and the LOCK_EX flag to prevent anyone else writing to the file at the same time
        file_put_contents($filename, "\n$dir\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Update the contents of the satis.json if and only if the contents changed.
     *
     * @return boolean True if the file was updated and false otherwise.
     */
    private function updateSatisFile(array $packages = []): bool
    {
        $rootDir = dirname(self::SATIS_FILE);

        if (!file_exists($rootDir)) {
            mkdir($rootDir, 0744, true);
            $this->addGitIgnore($rootDir);
        }

        $config = $this->getDefaultSatisConfig();

        $declared = $this->getDeclaredRepos($packages);
        $remoteUrl = $this->remoteUrl;
        $ns = $this->namespace;
        $baseName = $ns;
        $repositories = [];
        $require = [];

        foreach ($declared as $key => $repoName) {
            $repositories[] = [
                'type' => 'vcs',
                'url' => "$remoteUrl/$baseName/$repoName.git"
            ];

            $require["$ns/$repoName"] = '*';
        }

        $config['homepage'] = $this->localUrl;

        if ($repositories) {
            $config['repositories'] = $repositories;
        }

        if ($require) {
            $config['require'] = $require;
        }

        // Save the current satis config
        $this->satisConfig = $config;

        $old = self::encode(self::readJsonFile(self::SATIS_FILE));
        $new = self::encode($config);

        if ($old == $new) {
            return false;
        }

        $this->saveJsonFile(self::SATIS_FILE, $config);

        if (!is_file(self::SATIS_FILE)) {
            $this->writeError("There is no satis.json");
        }

        return true;
    }

    private function getComposerSettings()
    {
        $compFile = Factory::getComposerFile();
        $lockFile = Factory::getLockFile($compFile);

        $json = new JsonFile($compFile);

        $settings = file_get_contents($json->getPath());

        $locks = file_exists($lockFile) ? file_get_contents($lockFile) : null;
    }

    private function dispatch($name, $input, $output)
    {
        // From src/Composer/Command/RequireCommand.php
        // $commandEvent = new CommandEvent(
        //     PluginEvents::COMMAND,
        //     $name,
        //     $input,
        //     $output
        // );

        // self::$composer->getEventDispatcher()->dispatch(
        //     $commandEvent->getName(),
        //     $commandEvent
        // );
    }
}
