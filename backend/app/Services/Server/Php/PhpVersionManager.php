<?php

namespace App\Services\Server\Php;

use App\Exceptions\Server\Php\PhpConfigException;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * The PHP versions installed on this server, and their FPM configuration.
 *
 * Versions are detected from the same directory the Services feature reads, so
 * the two lists can never disagree about what exists.
 *
 * Editing an ini is a raw file edit, which is deliberate: whoever holds the
 * `service` permission on a single-tenant panel is the server administrator
 * and could edit the file over SSH regardless. What the panel adds is not
 * power — it is a safety net: the previous file is kept, the new one is
 * validated, and a configuration that fails its own test is rolled back
 * before anything reloads.
 */
class PhpVersionManager
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Installed PHP versions, newest first.
     *
     * @return array<int, string>
     */
    public function versions(): array
    {
        $dir = rtrim((string) config('server.php_dir', '/etc/php'), '/');

        if (! is_dir($dir)) {
            return [];
        }

        $versions = array_map(
            fn (string $fpm) => basename(dirname($fpm)),
            glob($dir.'/*/fpm', GLOB_ONLYDIR) ?: [],
        );

        usort($versions, fn (string $a, string $b) => version_compare($b, $a));

        return $versions;
    }

    public function exists(string $version): bool
    {
        return in_array($version, $this->versions(), true);
    }

    /**
     * Absolute path of a version's FPM php.ini.
     *
     * The version is checked against the detected list first, so a client
     * string can never be interpolated into a path we then write to.
     */
    public function iniPath(string $version): string
    {
        if (! $this->exists($version)) {
            throw PhpConfigException::unknownVersion($version);
        }

        return rtrim((string) config('server.php_dir', '/etc/php'), '/')."/{$version}/fpm/php.ini";
    }

    public function readIni(string $version): string
    {
        $result = $this->serverOps->run(
            ['cat', $this->iniPath($version)],
            ['feature' => 'php', 'op' => 'read_ini', 'version' => $version],
        );

        if ($result->failed()) {
            throw PhpConfigException::unreadable($version, $result->reference);
        }

        return $result->output();
    }

    /**
     * Replace a version's php.ini, validating before anything reloads.
     *
     * A bad ini is worse than a bad vhost: PHP-FPM may refuse to start at all,
     * taking every site on that version down with no obvious cause. So the old
     * file is kept and restored the moment the test fails, and the reload only
     * happens on a configuration that has already proven valid.
     */
    public function writeIni(string $version, string $contents): void
    {
        $path = $this->iniPath($version);
        $backup = $path.'.panel-bak';

        $this->must('backup', $this->serverOps->run(
            ['cp', '-f', $path, $backup],
            ['feature' => 'php', 'op' => 'backup_ini', 'version' => $version],
        ), $version);

        $this->must('write', $this->serverOps->run(
            ['tee', $path],
            ['feature' => 'php', 'op' => 'write_ini', 'version' => $version],
            input: $contents,
        ), $version);

        if ($this->test($version)->failed()) {
            // Put the working file back before the next reload — by us or by
            // anything else — can pick up the broken one.
            $this->serverOps->run(
                ['cp', '-f', $backup, $path],
                ['feature' => 'php', 'op' => 'restore_ini', 'version' => $version],
            );

            throw PhpConfigException::invalid($version);
        }

        $this->must('reload', $this->serverOps->run(
            ['systemctl', 'reload', "php{$version}-fpm"],
            ['feature' => 'php', 'op' => 'reload', 'version' => $version],
        ), $version);
    }

    /**
     * Validate a version's configuration without changing anything.
     */
    public function test(string $version): ServerOpsResult
    {
        return $this->serverOps->run(
            [(string) config('server.php_fpm_binary', '/usr/sbin/php-fpm').$version, '-t'],
            ['feature' => 'php', 'op' => 'config_test', 'version' => $version],
        );
    }

    private function must(string $step, ServerOpsResult $result, string $version): void
    {
        if ($result->failed()) {
            throw PhpConfigException::operationFailed($version, $result->reference);
        }
    }
}
