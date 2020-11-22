<?php

/**
 * @author    Proximify Inc <support@proximify.com>
 * @copyright Copyright (c) 2020, Proximify Inc
 * @license   MIT
 */

namespace Proximify\Packman;

use Exception;

defined('JSON_THROW_ON_ERROR') or define('JSON_THROW_ON_ERROR', 0);

/**
 * Package Manager
 */
class Satis
{
    private $options;
    private $satisConfig;
    private $satisStatus;
    private $buildCount;

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function listSatisRepos()
    {
        // print_r($this->findNeededRepos());
    }

    public function getSatisConfig()
    {
        return $this->satisConfig;
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

        $this->runSatisCommand('add', $options);
    }

    public function buildSatis()
    {
        $this->buildCount = 0;

        $this->buildSatisRecursive();
    }

    /**
     * Remove the vendor name from the given repository names.
     */
    public static function removeVendorName(string $vendor, array $repos): array
    {
        $vendor .= '/';
        $len = strlen($vendor);
        $names = [];

        foreach (array_keys($repos) as $key) {
            if (strncmp($key, $vendor, $len) === 0) {
                $names[] = substr($key, $len);
            }
        }

        return $names;
    }

    // private function getPrivateRepos(): array
    // {
    //     $require = $this->options['require'];
    //     $exclude = $this->options['exclude'];
    //     $add = $this->options['add'];
    // }

    public function getStatus(): array
    {
        return $this->satisStatus;
    }

    /**
     * Update the contents of the satis.json if and only if the contents changed.
     * The result of the update can be evaluated by analyzing the getStatus()
     * values.
     */
    public function updateSatisFile(array $newPackages = [], bool $reset = false): void
    {
        // Init the internal satis config
        $this->satisConfig = [];
        $this->satisStatus = [
            'needsReset' => $reset
        ];

        $declared = $this->findNeededRepos($newPackages);

        // Get the declared private package dependencies, including
        // the new requires and the dependencies of required packages
        if (!$declared) {
            return;
        }

        $config = $this->getDefaultSatisConfig();
        $vendor = $this->getVendorName();
        $remoteUrl = $this->options['remoteUrl'];
        $require = [];

        $repositories = [
            ['packagist.org' => false] // not really necessary
        ];

        foreach ($declared as $key => $repoName) {
            $repositories[] = [
                'type' => 'vcs',
                'url' => "$remoteUrl/$repoName.git"
            ];

            $require["$vendor/$repoName"] = '*';
        }

        $config['homepage'] = $this->options['localUrl'];

        // Check if the file is identical up to this point
        $oldSatisConfig = self::readJsonFile($this->getSatisFilename());
        $oldSatisRequire = $oldSatisConfig['require'] ?? [];

        // Remove old repositories and require before comparing...
        unset($oldSatisConfig['repositories']);
        unset($oldSatisConfig['require']);

        $newKeys = array_keys($require);

        if (self::encode($oldSatisConfig) == self::encode($config)) {
            $oldKeys = array_keys($oldSatisRequire);

            // $this->log($oldKeys, 'Old keys');
            // $this->log($newKeys, 'New keys');

            // Find new keys that are not among the old keys
            $diff = array_diff($newKeys, $oldKeys);

            // $this->log($diff, 'Diff');
        } else {
            $diff = $newKeys;

            $this->satisStatus['needsReset'] = true;

            // $this->log("New satis file is too different from before");
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
            $this->saveJsonFile($this->getSatisFilename(), $config);
        }

        $this->satisStatus['diff'] = $diff;
    }

    /**
     * Keep updating recursively until all dependencies are included
     * but stop if it's not making progress for some reason.
     */
    protected function buildSatisRecursive(): void
    {
        $this->buildCount++;

        $diff = $this->satisStatus['diff'];

        $result = $this->runSatisCommand('build');

        if ($result['code'] == 0) {
            $this->updateSatisFile();

            $diff2 = $this->satisStatus['diff'];

            if (!$diff2 || !is_array($diff2) || !array_diff($diff2, $diff)) {
                return; // there is nothing new to build
            }

            $this->buildSatisRecursive($diff2);
        }
    }

    protected function runSatisCommand(string $command, array $options = []): array
    {
        if (!($satisPath = $this->options['binPath'])) {
            throw new Exception("Cannot find satis binary");
        }

        $configPath = $this->getSatisFilename();
        $ourDir = $this->getSatisDir();

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

        $needsReset = $this->satisStatus['needsReset'] ?? false;

        if ($needsReset && is_dir($ourDir)) {
            $cmd = "rm -rf '$ourDir' && $cmd";
        }

        // Disable the reset for subsequent calls
        $this->satisStatus['needsReset'] = false;

        return self::execute($cmd, ['pipes' => $pipes]);
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
            'code' => proc_close($process),
            'command' => $cmd
        ];
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

    private function getSatisFilename(): string
    {
        return $this->options['rootDir'] . '/satis.json';
    }

    private function getSatisDir(): string
    {
        return $this->options['rootDir'] . '/repos';
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

    private function getVendorName(): ?string
    {
        return $this->options['vendor'];
    }

    /**
     * Get the union of all the 'require' arrays of all private packages.
     * Note: each repository is a set of packages, and a package is a particular
     * commit of a repository that is identified by a version and a branch.
     *
     * @return array
     */
    private function getDependencies(): array
    {
        $vendor = $this->getVendorName();

        if (!$vendor) {
            return [];
        }

        $path = $this->getSatisDir() . '/p2/' . $vendor;

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

    private function findNeededRepos(array $packages): array
    {
        if (!($vendor = $this->getVendorName())) {
            return [];
        }

        $packages = $this->options['require'] + $this->getDependencies()
            + $packages;

        foreach ($this->options['exclude'] ?? [] as $key) {
            unset($packages[$key]);
        }

        return self::removeVendorName($vendor, $packages);
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
}
