<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Php\PhpConfigException;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * The PHP versions installed on this server, and their FPM configuration.
 *
 * Versions come from the same directory the Services feature reads, so the two lists agree.
 *
 * Editing an ini is a raw file edit on purpose: the `service` permission holder is the server
 * administrator anyway. The panel adds a safety net: the previous file is kept and a
 * configuration that fails its own test is rolled back before anything reloads.
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
     * The version is checked against the detected list first, so a client string is never
     * interpolated into a path we write to.
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
     * A bad ini can stop PHP-FPM from starting and take every site on that version down, so
     * the old file is restored the moment the test fails and the reload only follows a valid one.
     *
     * With `$keepCopy` false no `.panel-bak` is written; the old text is held in memory and
     * written back if PHP refuses the new one.
     */
    public function writeIni(string $version, string $contents, bool $keepCopy = true): void
    {
        $path = $this->iniPath($version);
        $this->assertExtensionsStayHome($version, $contents);
        $backup = $path.'.panel-bak';
        $original = null;

        if ($keepCopy) {
            $this->must('backup', $this->serverOps->run(
                ['cp', '-f', $path, $backup],
                ['feature' => 'php', 'op' => 'backup_ini', 'version' => $version],
            ), $version);
        } else {
            $original = $this->readIni($version);
        }

        $this->must('write', $this->serverOps->run(
            ['tee', $path],
            ['feature' => 'php', 'op' => 'write_ini', 'version' => $version],
            input: $contents,
        ), $version);

        $test = $this->test($version);

        if ($test->failed()) {
            // Restore before any later reload can pick up the broken file.
            $this->serverOps->run(
                $original === null ? ['cp', '-f', $backup, $path] : ['tee', $path],
                ['feature' => 'php', 'op' => 'restore_ini', 'version' => $version],
                input: $original,
            );

            throw PhpConfigException::invalid($version, $this->complaints($test));
        }

        $this->must('reload', $this->stack->reload($version), $version);
    }

    /**
     * Refuse an extension loaded from anywhere but PHP's own directory.
     *
     * The PHP-FPM master runs as root and loads every `extension` and `zend_extension` at
     * start, so a path to any `.so` is code run as root. A bare name, or an absolute path
     * straight inside the directory, is fine. `..`, environment variables and moving
     * `extension_dir` itself are refused.
     *
     * The directory comes from the binary's compiled-in PHP_EXTENSION_DIR, run with `-n` so
     * the ini being saved cannot move it.
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
            // `$`: PHP expands `${VAR}` in ini values, so a path may not be where it looks.
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

    /**
     * What PHP said about the file: its error and warning lines, at most ten (e.g.
     * `PHP:  syntax error, unexpected '=' in /etc/php/8.3/fpm/php.ini on line 912`).
     *
     * @return array<int, string>
     */
    private function complaints(ServerOpsResult $test): array
    {
        $lines = preg_split('/\r?\n/', $test->output()."\n".$test->errorOutput()) ?: [];

        return array_slice(array_values(array_unique(array_filter(
            array_map('trim', $lines),
            fn (string $line): bool => preg_match('/(syntax error|Error parsing|error|warning|unable|unknown)/i', $line) === 1
                && ! str_contains($line, 'test is successful'),
        ))), 0, 10);
    }

    private function must(string $step, ServerOpsResult $result, string $version): void
    {
        if ($result->failed()) {
            throw PhpConfigException::operationFailed($version, $result->reference);
        }
    }
}
