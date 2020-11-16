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

/**
 * Package Manager
 */
class Packman
{
    const YELLOW_CIRCLE = "\u{1F7E1}";
    const GREEN_CIRCLE = "\u{1F7E2}";
    const RED_CIRCLE = "\u{1F534}";
    const OUTPUT_DIR = 'private-packages';

    private $handle;
    private $domain;
    private $output;
    private $settings;
    private $localUrl;
    private $remoteUrl;
    private $namespace;

    public function __construct()
    {
    }

    public function __destruct()
    {
        $this->stopServer();
    }

    public function readComposerFile()
    {
        $this->settings = self::readJsonFile('composer.json');

        $extras = $this->settings['extras']['packman'] ?? [];

        $this->localUrl = $extras['localUrl'] ?? 'http://localhost:8081';
        $this->remoteUrl = $extras['remoteUrl'] ?? 'https://github.com/';
        $this->namespace = $extras['namespace'] ?? false;

        if (!$this->namespace) {
            $name = $this->settings['name'] ?? '';
            $pos = strpos($name, '/');

            if (!$name || !$pos) {
                throw new Exception("Cannot find namespace in composer.json");
            }

            $this->namespace = substr($name, 0, $pos);
        }
    }

    public function runCommand(string $name, InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $this->readComposerFile();

        // The command name is also at: $input->getOption('command');
        // $options = $input->getArguments();
        // print_r($options);

        $this->startServer();

        $this->writeln("Executing '$name'...");

        switch ($name) {
            case 'packman-init':
            case 'init-packman':
                return $this->init();
            case 'packman-update':
            case 'update-packman':
                return $this->updateSatis();
        }
    }

    static private function readJsonFile(string $filename)
    {
        if (!is_file($filename)) {
            return [];
        }

        $json = file_get_contents($filename);

        return json_decode($json, true) ?? [];
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
        //self::readJsonFile('satis.json');
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

    private function updateSatisFile()
    {
        $config = $this->getDefaultSatisConfig();

        $declared = $this->getDeclaredRepos();
        $remoteUrl = '';
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
        $config['repositories'] = $repositories;
        $config['require'] = $require;

        print_r($config);
    }

    public function updateSatis()
    {
        $this->updateSatisFile();

        // print_r($this->settings);

        if (!is_file('satis.json')) {
            $this->writeln("There is no satis.json");
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

        $this->writeln("Running: $cmd ...");

        $status = self::execute($cmd, $options);

        if ($status['out'])
            $this->writeln($status['out']);

        if ($status['err'])
            $this->writeln($status['err'], $status['code']);
    }

    protected function startServer($port = '8081', $target = self::OUTPUT_DIR)
    {
        if ($this->handle) {
            return $this->handle;
        }

        if (!is_dir($target)) {
            return;
        }

        $this->domain = "localhost:$port";

        $this->writeln("Creating web server at $this->domain from $target...");

        $this->handle = proc_open("php -S $this->domain -t '$target'", [], $pipes);

        return $this->handle;
    }

    protected function stopServer()
    {
        if ($this->handle) {
            $this->writeln("Stopping server...\n");
            proc_terminate($this->handle);
            $this->handle = null;
            $this->writeln("Server stopped!\n");
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

    private function writeln(string $msg, ?int $code = null)
    {
        if ($this->output) {
            $prompt = is_null($code) ? self::YELLOW_CIRCLE : ($code ?
                self::RED_CIRCLE : self::GREEN_CIRCLE);

            $this->output->writeln("$prompt $msg");
        }
    }
}
