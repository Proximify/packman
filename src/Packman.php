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

defined('JSON_THROW_ON_ERROR') or define('JSON_THROW_ON_ERROR', 0);

/**
 * Package Manager
 *
 * Note: Get RootPackage with $composer->getPackage()->getMinimumStability()
 *
 * @see findBestVersionAndNameForPackage in src/Composer/Command/InitCommand.php
 * $this->normalizeRequirements($requires);
 * @see src/Composer/InstalledVersions.php
 * @see src/Composer/Factory.php [has static methods]
 * @see src/Composer/Util/RemoteFilesystem.php
 * @see src/Composer/Json/JsonFile.php
 *
 * Skip with: https://packagist.org/packages/list.json?vendor=proximify
 * https://packagist.org/apidoc
 */
class Packman
{
    const YELLOW_CIRCLE = "\u{1F7E1}";
    const RED_CIRCLE = "\u{1F534}";
    // const GREEN_CIRCLE = "\u{1F7E2}";
    // const PACKMAN_ICON = "\u{25D4}";
    const OUTPUT_DIR = 'private-packages/repos';
    const SATIS_FILE = 'private-packages/satis.json';
    const SEPARATOR = '-----------------------------------';

    const NORMAL = IOInterface::NORMAL;
    const VERBOSE = IOInterface::VERBOSE;

    /**
     * @var int Counter of Packman current instances. Used to remove
     * static assets.
     */
    private static $instanceCount = 0;

    /** @var mixed Handle for a web server process. */
    private static $serverHandle;
    private static $composer;
    private static $io;

    private $output;
    private $settings;
    private $localUrl;
    private $remoteUrl;
    private $namespace;
    private $satisConfig;
    private $satisStatus;
    // private $versions;
    private $publicPackages;
    private $newRequire = [];
    private $buildCount;

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

    public function start(array $packages = [], $reset = false)
    {
        // Always read the composer file to init properties
        if (!$this->readComposerFile()) {
            return;
        }

        $diff = $this->updateSatisFile($packages);

        if ($reset) {
            $this->satisStatus['needsReset'] = true;
        }

        $needsBuild = $this->satisStatus['needsBuild'] ?? false;
        $needsReset = $this->satisStatus['needsReset'] ?? false;

        if (!$needsBuild) {
            return;
        }

        // If no reset is needed, the server can be started right away
        $needsReset ? $this->stopServer() : $this->startServer();

        $this->buildSatis($diff);

        // If a reset was done, the server is started after it
        if ($needsReset) {
            $this->startServer();
        }

        $this->addLocalServerToComposer();
    }

    public function runCommand(
        string $name,
        InputInterface $input,
        OutputInterface $output
    ) {
        $this->output = $output;

        // Read the composer file to init properties
        if (!$this->readComposerFile()) {
            return;
        }

        switch ($name) {
            case 'packman-list':
                return $this->listSatisRepos();
            case 'packman-update':
                return $this->buildSatis($this->updateSatisFile());
            case 'purge':
                return $this->runSatisCommand('purge');
            case 'reset':
                return $this->start([], true);
        }
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

    public function listSatisRepos()
    {
        print_r($this->getDeclaredRepos());
    }

    /**
     * The 'add' command of satis simply modifies the satis.json file. The same
     * generic build step has to be run after. We can edit satis.json directly
     * so the command is not used.
     *
     * @param array $package
     * @return void
     */
    public function addSatisRepo(array $package)
    {
        // see vendor/composer/satis/src/Console/Command/AddCommand.php
        $options = [
            'useToken' => false, // should be read from composer's extras
            'name' => $package['name'],
        ];

        $this->writeMsg("Adding package '$package'...");

        $this->runSatisCommand('add', $options);
    }

    public function buildSatis($diff)
    {
        $this->buildCount = 0;

        $this->buildSatisRecursive($diff);

        $this->log("Satis build is complete");
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
     * Keep updating recursively until all dependencies are included
     * but stop if it's not making progress for some reason.
     *
     * @param array $options
     * @param array|bool $diff List of missing packages.
     * @return void
     */
    protected function buildSatisRecursive($diff): void
    {
        if ($diff) {
            $this->log($diff, 'Current differences');
        }

        $this->buildCount++;

        $this->log("Building missing packages [$this->buildCount]");

        if ($this->runSatisCommand('build')) {
            $diff2 = $this->updateSatisFile();

            if (!$diff2 || !is_array($diff2) || !array_diff($diff2, $diff)) {
                return; // there is nothing new to build
            }

            $this->buildSatisRecursive($diff2);
        }
    }

    protected function runSatisCommand(string $command, array $options = []): bool
    {
        $satisPath = self::$composer->getConfig()->get('bin-dir') . '/satis';

        if (!is_file($satisPath)) {
            // Try using the global bin-dir
            $pm = self::$composer->getPluginManager();
            $globalComposer = $pm->getGlobalComposer();
            $satisPath = $globalComposer->getConfig()->get('bin-dir') . '/satis';

            if (!is_file($satisPath)) {
                $this->writeError("Cannot find satis binary");
                return false;
            }
        }

        $configPath = self::SATIS_FILE;
        $ourDir = self::OUTPUT_DIR;

        $cmd = "php '$satisPath' $command '$configPath' '$ourDir'";

        if ($options['useToken'] ?? false) {
            // The stdin and the stderr need to be connected to the
            // console's so the token can be entered when prompted
            $pipes = [
                'stderr' => fopen('php://stderr', 'w')
                //stdin ?
            ];
        } else {
            // -n (or --no-interaction) is used to use the ssh key of the
            // machine instead of asking for a token.
            $cmd .= ' -n';
            $pipes = []; // no pipes needed
        }

        $msg = "Running satis $command...";

        $needsReset = $this->satisStatus['needsReset'] ?? false;

        if ($needsReset && is_dir($ourDir)) {
            $cmd = "rm -rf '$ourDir' && $cmd";
            $msg = "Resetting satis. $msg";

            // Disable the reset for subsequent calls
            $this->satisStatus['needsReset'] = false;
        }

        $this->log(self::SEPARATOR);
        $this->writeMsg($msg);
        $this->log($cmd);

        $status = self::execute($cmd, ['pipes' => $pipes]);

        $success = ($status['code'] == 0);
        $level = $success ? self::VERBOSE : self::NORMAL;

        if ($msg = trim($status['out'])) {
            $this->writeMsg($msg, $level);
        }

        if ($msg = self::cleanSatisError($status['err'], $level)) {
            $this->writeError($msg, $level);
        }

        $this->log(self::SEPARATOR);

        return $success;
    }

    /**
     * Start the web server.
     *
     * @return void
     */
    public function startServer()
    {
        if (self::$serverHandle) {
            return;
        }

        $target = self::OUTPUT_DIR;

        if (!is_dir($target)) {
            return;
        }

        if (!$this->settings && !$this->readComposerFile()) {
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

        $this->writeMsg("Starting web server...");

        $cmd = "php -S '$host' -t '$target'";

        self::$serverHandle = proc_open($cmd, [], $pipes);
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
        $errPipe = $options['pipes']['stderr'] ?? null;

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

    private function addLocalServerToComposer()
    {
        // Disable the secure HTTP restriction (or 'disable-tls' => false)
        $this->setComposerConfig('secure-http', false);

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

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?: [];
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function saveJsonFile(string $filename, array $data)
    {
        file_put_contents($filename, self::encode($data));
    }

    private function getSatisRepoDependencies(): array
    {
        if (!$this->namespace) {
            return [];
        }

        $path = self::OUTPUT_DIR . '/p2/' . $this->namespace;

        if (!is_dir($path)) {
            return [];
        }

        $dir = new \DirectoryIterator($path);
        $repos = [];

        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                $data = self::readJsonFile($fileinfo->getPathname());

                foreach ($data['packages'] ?? [] as $name => $pkg) {
                    foreach ($pkg as $ref) {
                        $repos += $ref['require'] ?? [];
                    }
                }
            }
        }

        return $repos;
    }

    private function getPublicPackages(string $vendor)
    {
        $url = 'https://packagist.org/packages/list.json?vendor=' . $vendor;

        if ($this->publicPackages === null) {
            $response = json_decode($this->getRemoteContents($url, []), true);
            $this->publicPackages = $response['packageNames'] ?? [];
        }

        // $this->log($this->publicPackages, 'public packages');

        return $this->publicPackages;
    }

    private function getDeclaredRepos(): array
    {
        $this->log($this->newRequire, 'new require');

        $packages = ($this->settings['require'] ?? []) +
            ($this->settings['require-dev'] ?? []) +
            $this->getSatisRepoDependencies() + $this->newRequire;

        $exclude = $this->getPublicPackages($this->namespace);

        foreach ($exclude as $key) {
            unset($packages[$key]);
        }

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

    /**
     * Update the contents of the satis.json if and only if the contents changed.
     *
     * @return boolean|array False if there are no difference from the
     * previous satis config. True if the difference is greatest (needs reset),
     * or an array with the new repos that should be added.
     */
    private function updateSatisFile(array $packages = []): array
    {
        // Init the internal satis config
        $this->satisConfig = [];
        $this->satisStatus = [];

        // Add given packages to the active new requires.
        $this->newRequire += $packages;

        // Get the declared private package dependencies, including
        // the new requires and the dependencies of required packages
        $declared = $this->getDeclaredRepos();

        if (!$declared) {
            // There are no private packages
            return false;
        }

        if (!$this->localUrl) {
            $this->writeError("There is no URL for the packman server");
            return false;
        }

        $this->log($declared, 'Declared');

        // Create the private_repositories folder if it doesn't exist
        // It is the parent dir of the satis file.
        $rootDir = dirname(self::SATIS_FILE);

        if (!file_exists($rootDir)) {
            mkdir($rootDir, 0744, true);
            $this->addGitIgnore($rootDir);
        }

        $config = $this->getDefaultSatisConfig();

        $remoteUrl = $this->remoteUrl;
        $ns = $this->namespace;
        $baseName = $ns;
        $require = [];

        $repositories = [
            ['packagist.org' => false] // not really necessary
        ];

        foreach ($declared as $key => $repoName) {
            $repositories[] = [
                'type' => 'vcs',
                'url' => "$remoteUrl/$baseName/$repoName.git"
            ];

            $require["$ns/$repoName"] = '*';
        }

        $config['homepage'] = $this->localUrl;

        // Check if the file is identical up to this point
        $oldSatisConfig = self::readJsonFile(self::SATIS_FILE);
        $oldSatisRequire = $oldSatisConfig['require'] ?? [];

        // Remove old repositories and require before comparing...
        unset($oldSatisConfig['repositories']);
        unset($oldSatisConfig['require']);

        $newKeys = array_keys($require);

        if (self::encode($oldSatisConfig) == self::encode($config)) {
            $oldKeys = array_keys($oldSatisRequire);

            $this->log($oldKeys, 'Old keys');
            $this->log($newKeys, 'New keys');

            // Find new keys that are not among the old keys
            $diff = array_diff($newKeys, $oldKeys);

            $this->log($diff, 'Diff');
        } else {
            $diff = $newKeys;

            $this->satisStatus['needsReset'] = true;

            $this->log("New satis file is too different from before");
            // $this->log($oldSatisConfig, 'old config');
            // $this->log($config, 'Config');
        }

        if ($repositories) {
            $config['repositories'] = $repositories;
        }

        // Don't save empty arrays because they become [] instead of {}
        // which gets rejected by the json schema of satis
        if ($require) {
            $config['require'] = $require;
            $this->satisStatus['needsBuild'] = true;
        }

        // Save the new satis config
        $this->satisConfig = $config;

        if ($diff) {
            $this->saveJsonFile(self::SATIS_FILE, $config);
        }

        return $diff;
    }

    private function setComposerConfig(string $key, $value)
    {
        $config = [
            'config' => [$key => $value]
        ];

        self::$composer->getConfig()->merge($config);
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

    private static function cleanSatisError($msg, $level)
    {
        if ($level == self::VERBOSE) {
            return;
        }

        // Remove the <warning></warning> tag
        $msg = strip_tags($msg);

        $annoying = 'The "proximify/packman" plugin was skipped because it requires a Plugin API version ("^2.0") that does not match your Composer installation ("1.1.0"). You may need to run composer update with the "--no-plugins" option.';

        $msg = str_replace($annoying, '', $msg);

        return trim(self::trim_all($msg));
    }

    static function trim_all($str, $what = NULL, $with = ' ')
    {
        if ($what === NULL) {
            //  Character      Decimal      Use
            //  "\0"            0           Null Character
            //  "\t"            9           Tab
            //  "\n"           10           New line
            //  "\x0B"         11           Vertical Tab
            //  "\r"           13           New Line in Mac
            //  " "            32           Space

            $what   = "\\x00-\\x20";    //all white-spaces and control chars
        }

        return trim(preg_replace("/[" . $what . "]+/", $with, $str), $what);
    }
}
