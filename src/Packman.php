<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin;

use Exception;
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
    private $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function __destruct()
    {
        $this->stopServer();
    }

    public function runCommand(string $name, ?OutputInterface $output = null)
    {
        $this->startServer();

        $this->output = $output;

        $this->writeln("Executing '$name'...");

        switch ($name) {
            case 'packman-init':
                return $this->init();
            case 'packman-update':
                return $this->updateSatis();
        }
    }

    public function init()
    {
    }

    public function updateSatis()
    {
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
