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

/**
 * Package Manager
 */
class Packman
{
    const YELLOW_CIRCLE = "\u{1F7E1}";
    const GREEN_CIRCLE = "\u{1F7E2}";
    const RED_CIRCLE = "\u{1F534}";
    const PACKMAN_ICON = "\u{25D4}";
    const OUTPUT_DIR = 'private-packages';
    const SATIS_FILE = 'satis.json';

    /**
     * @var int Counter of Packman current instances. Used to remove
     * static assets.
     */
    private static $instanceCount = 0;

    /** @var mixed Handle for a web server process. */
    private static $handle;
    private static $composer;

    private $output;
    private $settings;
    private $localUrl;
    private $remoteUrl;
    private $namespace;
    private $satisConfig;

    public function __construct()
    {
        self::$instanceCount++;
    }

    public function __destruct()
    {
        self::$instanceCount--;

        if (self::$instanceCount == 0) {
            self::$composer = null;
            $this->stopServer();
        }
    }

    public function start(?Composer $composer = null)
    {
        if ($composer) {
            self::$composer = $composer;
        }

        // Always read the composer file to init properties
        $this->readComposerFile();
        $this->updateSatis();

        if (!$this->needsServer()) {
            return;
        }

        if ($this->checkComposerFile()) {
            $this->startServer();
        } else {
            $this->writeln("Please run 'composer packman-init'", 1);
        }
    }

    public function runCommand(string $name, InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        // Always read the composer file to init properties
        $this->readComposerFile();

        // The server and the composer object are set by a different Packman
        // object in static properties, so they are available to the object
        // created for answering CLI commands.
        // $this->start();

        // The command name is also at: $input->getOption('command');
        // $options = $input->getArguments();
        // print_r($options);
        // $this->writeln("Executing '$name'...");

        switch ($name) {
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

    public function updateSatis()
    {
        $changed = $this->updateSatisFile();

        if (!is_file(self::SATIS_FILE)) {
            $this->writeln("There is no satis.json");
        }

        // Check if the satis file defines a homepage and requirements
        if (!$this->needsServer()) {
            return;
        }

        $options = [];

        $ourDir = self::OUTPUT_DIR;
        $cmd = "php vendor/bin/satis build satis.json '$ourDir'";

        if (empty($this->options['interactive'])) {
            // -n (or --no-interaction) is used to use the ssh key of the
            // machine instead of asking for a token.
            $cmd .= ' -n';
        } else {
            // Show the request coming from the STDERR
            $options['stderr'] = fopen('php://stderr', 'w');
        }

        if ($changed && is_dir($ourDir)) {
            $cmd = "rm -rf '$ourDir' && $cmd";
            $this->writeln("Recomputing all private packages...");
        } else {
            $this->writeln("Refreshing private packages...");
        }

        $status = self::execute($cmd, $options);
        $code = $status['code'];

        if ($status['out']) {
            $this->writeln($status['out'], $code);
        }

        if ($status['err']) {
            $this->writeln($status['err'], $code);
        }

        $this->writeln("Packages update complete", $code);
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

        // if ($composer) {
        //     $this->composer = $composer;
        // }

        $url = parse_url($this->localUrl);

        // Default in case the URL is missing parts. The actual default
        // localUrl is set by readComposerFile()
        $host = $url['host'] ?? 'localhost';

        if ($port = $url['port'] ?? false) {
            $host .= ":$port";
        }

        $this->writeln("Creating web server at $host from $target...");

        $cmd = "php -S $host -t '$target'";

        $this->writeln($cmd);

        self::$handle = proc_open($cmd, [], $pipes);
    }

    protected function readComposerFile()
    {
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
                throw new Exception("Cannot find namespace in composer.json");
            }

            $this->namespace = substr($name, 0, $pos);
        }
    }

    protected function stopServer()
    {
        if (self::$handle) {
            $this->writeln("Stopping server...");
            proc_terminate(self::$handle);
            self::$handle = null;
            $this->writeln("Server stopped!");
        }
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
            throw new Exception("Cannot execute command");
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

    private function getDeclaredRepos(): array
    {
        $packages = ($this->settings['require'] ?? []) +
            ($this->settings['require-dev'] ?? []);

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

    /**
     * Update the contents of the satis.json if and only if the contents changed.
     *
     * @return boolean True if the file was updated and false otherwise.
     */
    private function updateSatisFile(): bool
    {
        $config = $this->getDefaultSatisConfig();

        $declared = $this->getDeclaredRepos();
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

        return true;
    }

    private function writeln(string $msg, ?int $code = null)
    {
        $prompt = is_null($code) ? self::YELLOW_CIRCLE : ($code ?
            self::RED_CIRCLE : self::GREEN_CIRCLE);

        $msg = "$prompt $msg";

        ($this->output) ? $this->output->writeln($msg) : print("$msg\n");
    }
}
