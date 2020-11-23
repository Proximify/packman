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
 * Make use of the CLI command 'satis' to fetch private packages
 * and build a package manager.
 */
class Satis
{
    const SATIS_DIR_NAME = 'repos';

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
        $remoteUrl = rtrim($this->options['remoteUrl'], '/');
        $require = [];

        $repositories = [
            ['packagist.org' => false] // not really necessary
        ];

        foreach ($declared as $repoName) {
            $repositories[] = [
                'type' => 'vcs',
                'url' => "$remoteUrl/$repoName.git"
            ];

            $require["$vendor/$repoName"] = '*';
        }

        // Packman::log($repositories);

        $config['homepage'] = $this->options['localUrl'];

        // Check if the file is identical up to this point
        $oldSatisConfig = self::readJsonFile($this->getSatisFilename());
        $oldSatisRequire = $oldSatisConfig['require'] ?? [];

        // Remove old repositories and require before comparing...
        unset($oldSatisConfig['repositories']);
        unset($oldSatisConfig['require']);

        $newKeys = array_keys($require);

        // Packman::log($declared, 'declared');

        if (self::encode($oldSatisConfig) == self::encode($config)) {
            $oldKeys = array_keys($oldSatisRequire);

            // Packman::log($oldKeys, 'Old keys');
            // Packman::log($newKeys, 'New keys');

            // Find new keys that are not among the old keys
            $diff = array_diff($newKeys, $oldKeys);

            // Packman::log($diff, 'Diff');
        } else {
            $diff = $newKeys;

            $this->satisStatus['needsReset'] = true;

            // Packman::log("New satis file is too different from before");
            // Packman::log($oldSatisConfig, 'old config');
            // Packman::log($config, 'Config');
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
            $dir = $this->options['rootDir'];
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                $this->addToGitIgnore($dir);
            }

            $this->saveJsonFile($this->getSatisFilename(), $config);
        }

        $this->satisStatus['diff'] = $diff;
    }

    public function getOutputDir(): string
    {
        return $this->options['rootDir'] . '/' . self::SATIS_DIR_NAME;
    }

    /**
     * Get the union of all the 'require' arrays of all private packages.
     * Note: each repository is a set of packages, and a package is a particular
     * commit of a repository that is identified by a version and a branch.
     *
     * @return array
     */
    public function getDependencies(): array
    {
        $vendor = $this->getVendorName();

        if (!$vendor) {
            return [];
        }

        $path = $this->getOutputDir() . '/p2/' . $vendor;

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

    public function purge()
    {
        return $this->runSatisCommand('purge');
    }

    protected function addToGitIgnore(string $dir): void
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

    protected function getSatisBinPath(): ?string
    {
        return $this->options['binPath'] ?? null;
    }

    protected function runSatisCommand(string $command, array $options = []): array
    {
        $satisPath = $this->getSatisBinPath();
        $configPath = $this->getSatisFilename();
        $ourDir = $this->getOutputDir();

        if (!$satisPath || !$configPath || !$ourDir) {
            throw new Exception("Cannot run satis");
        }

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

    /**
     * Keep updating recursively until all dependencies are included
     * but stop if it's not making progress for some reason.
     */
    protected function buildSatisRecursive(): void
    {
        $this->buildCount++;

        $diff = $this->satisStatus['diff'];

        $result = $this->runSatisCommand('build');

        $success = ($result['code'] == 0);
        $level = $success ? Packman::NORMAL : Packman::VERBOSE;

        if ($msg = trim($result['out'])) {
            Packman::writeMsg($msg, $level);
        }

        if ($msg = self::cleanSatisError($result['err'], $level)) {
            Packman::writeError($msg, true, $level);
        }

        // $this->log($result, '$result');
        if ($success) {
            $this->updateSatisFile();

            $diff2 = $this->satisStatus['diff'];

            if (!$diff2 || !is_array($diff2) || !array_diff($diff2, $diff)) {
                return; // there is nothing new to build
            }

            $this->buildSatisRecursive($diff2);
        }
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

    private function getSatisFilename(): string
    {
        return $this->options['rootDir'] . '/satis.json';
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

    private static function cleanSatisError(string $msg, $level): string
    {
        if ($level == Packman::VERBOSE) {
            return $msg;
        }

        // Remove the <warning></warning> tag
        $msg = strip_tags($msg);

        $annoying = 'The "proximify/packman" plugin was skipped because it requires a Plugin API version ("^2.0") that does not match your Composer installation ("1.1.0"). You may need to run composer update with the "--no-plugins" option.';

        $msg = str_replace($annoying, '', $msg);

        return self::trimAll($msg);
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
}
