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
 *
 * https://github.com/composer/composer/
 * @see composer/src/Composer/Plugin/PluginInterface.php
 */
class Packman
{
    const OUTPUT_DIR = 'private-packages';
    private $handle;
    private $domain;

    public function __construct()
    {
        $this->startServer();
    }

    public function __destruct()
    {
        $this->stopServer();
    }

    public function init()
    {
    }

    public function updateSatis()
    {
        if (!is_file('satis.json')) {
            echo "\nThere is no satis.json\n";
        }

        // -n (or --no-interaction) is used to use the ssh key of the machine
        // instead of asking for a token.
        $ourDir = self::OUTPUT_DIR;
        $cmd = "php vendor/bin/satis build satis.json '$ourDir' -n";

        print_r("\nCOMMAND: $cmd ...\n");

        $options = [
            'stderr' => fopen('php://stderr', 'w')
        ];

        $status = self::execute($cmd, $options);

        print_r($status);
    }

    protected function startServer($port = '8081', $target = self::OUTPUT_DIR)
    {
        if ($this->handle) {
            return $this->handle;
        }

        $this->domain = "localhost:$port";

        echo "\nCreating server at $this->domain...\n";

        $this->handle = proc_open("php -S $this->domain -t '$target'", [], $pipes);

        return $this->handle;
    }

    protected function stopServer()
    {
        if ($this->handle) {
            echo 'CLOSING';
            proc_close($this->handle);
            $this->handle = null;
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
            echo "\nCLOSING 2\n";
            fclose($pipes[2]);
            echo "\nCLOSED 2\n";
        }

        return [
            'out' => trim($stdout),
            'err' => trim($stderr),
            'code' => proc_close($process)
        ];
    }
}
