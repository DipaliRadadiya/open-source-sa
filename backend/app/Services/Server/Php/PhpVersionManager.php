<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
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
    public function __construct(
        private ServerOps $serverOps,
        private PhpStack $stack,
    ) {}

    /**
     * Installed PHP versions, newest first.
     *
     * @return array<int, string>
     */
    public function versions(): array
    {
        return $this->stack->versions();
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

        return $this->stack->iniPath($version);
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
        $this->assertExtensionsStayHome($version, $contents);
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

        $this->must('reload', $this->stack->reload($version), $version);
    }

    /**
     * Refuse an extension loaded from anywhere but PHP's own directory
     * (bug #28).
     *
     * The PHP-FPM master runs as root and loads every `extension` and
     * `zend_extension` at start, so a path to any `.so` is code run as root.
     * A bare name, or an absolute path straight inside the directory (where
     * v7 copies the ionCube loader), is fine. `..` cannot pass the
     * directory comparison; also closed: environment variables, and moving
     * `extension_dir` itself.
     *
     * The directory comes from the binary's compiled-in PHP_EXTENSION_DIR,
     * with `-n`: the ini being saved cannot move it.
     */
    private function assertExtensionsStayHome(string $version, string $contents): void
    {
        $directory = null;

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(zend_extension|extension|extension_dir)\s*=\s*(.*)$/i', $line, $m) !== 1) {
                continue;
            }

            $key = strtolower($m[1]);
            $value = $this->iniValue($m[2]);
            $bare = $key !== 'extension_dir' && preg_match('/^[A-Za-z0-9_.-]+$/', $value) === 1;

            if ($bare) {
                continue;
            }

            $directory ??= $this->extensionDirectory($version);
            // `$`: PHP expands `${VAR}` in ini values when it reads them, so a
            // path that looks like it is inside the directory may not be.
            $allowed = ! str_contains($value, '$') && match ($key) {
                'extension_dir' => rtrim($value, '/') === $directory,
                default => str_starts_with($value, '/') && dirname($value) === $directory,
            };

            if (! $allowed) {
                throw PhpConfigException::extensionOutsideDirectory(trim($line), $directory);
            }
        }
    }

    /** An ini value without its quotes or trailing comment. */
    private function iniValue(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^"([^"]*)"|^\'([^\']*)\'/', $raw, $m) === 1) {
            return $m[1] !== '' ? $m[1] : ($m[2] ?? '');
        }

        return trim(explode(';', $raw, 2)[0]);
    }

    private function extensionDirectory(string $version): string
    {
        $result = $this->serverOps->run(
            [$this->stack->binaryPath($version), '-n', '-r', 'echo PHP_EXTENSION_DIR;'],
            ['feature' => 'php', 'op' => 'extension_dir', 'version' => $version],
        );
        $directory = rtrim(trim($result->output()), '/');

        if ($result->failed() || ! str_starts_with($directory, '/')) {
            throw PhpConfigException::operationFailed($version, $result->reference);
        }

        return $directory;
    }

    /**
     * Validate a version's configuration without changing anything.
     */
    public function test(string $version): ServerOpsResult
    {
        return $this->stack->configTest($version);
    }

    private function must(string $step, ServerOpsResult $result, string $version): void
    {
        if ($result->failed()) {
            throw PhpConfigException::operationFailed($version, $result->reference);
        }
    }
}
