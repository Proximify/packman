<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\ComposerPlugin;

use Exception;

/**
 * Package Manager
 *
 * @see composer/src/Composer/Plugin/PluginInterface.php
 */
class PM
{
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

    public function startServer($port = '8081', $target = 'public')
    {
        if ($this->handle) {
            return $this->handle;
        }

        $this->domain = "localhost:$port";
        $this->handle = proc_open("php -S $this->domain -t '$target'", [], $pipes);

        return $this->handle;
    }

    public function stopServer()
    {
        if ($this->handle) {
            echo 'CLOSING';
            proc_close($this->handle);
            $this->handle = null;
        }
    }

    public function updateSatis()
    {
        if (!is_file('satis.json')) {
            echo "\nThere is no satis.json\n";
        }

        $cmd = 'php vendor/bin/satis build satis.json private-packages';

        print_r($cmd);
        $status = self::execute($cmd);

        print_r($status);
    }

    protected static function execute(string $cmd, ?string $cwd = null, ?array $env = null): array
    {
        $descriptor = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptor, $pipes, $cwd, $env);

        if (!$process) {
            throw new Exception("Cannot execute command");
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [
            'out' => trim($stdout),
            'err' => trim($stderr),
            'code' => proc_close($process)
        ];
    }
}
