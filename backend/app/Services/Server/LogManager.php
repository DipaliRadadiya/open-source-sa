<?php

namespace App\Services\Server;

/**
 * Read-only access to server log files. No DB — the catalog is the configured
 * source registry plus per-version php-fpm logs detected from `php_dir`,
 * filtered at read time to files that actually exist (detect-don't-trust).
 *
 * Callers reference a source by its `key`; this class resolves the real path
 * from the registry — a client-supplied path is never read (no traversal).
 * Reads are native filesystem operations (efficient tail / byte-range / grep),
 * not shelled commands, so there is no injection surface.
 */
class LogManager
{
    public const DEFAULT_LINES = 200;

    public const MAX_LINES = 5000;

    /**
     * All configured sources that exist on this box, with live metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return array_values(array_filter(array_map(
            fn (array $source) => $this->describe($source),
            $this->catalog(),
        )));
    }

    /**
     * The registry entry for a key, or null if it isn't a managed source.
     *
     * @return array{key: string, label: string, group: string, path: string}|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->catalog() as $source) {
            if ($source['key'] === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Display metadata for one source, or null when the file doesn't exist.
     *
     * @param  array{key: string, label: string, group: string, path: string}  $source
     * @return array<string, mixed>|null
     */
    public function describe(array $source): ?array
    {
        if (! is_file($source['path'])) {
            return null;
        }

        return [
            'key' => $source['key'],
            'label' => $source['label'],
            'group' => $source['group'],
            'size' => (int) (filesize($source['path']) ?: 0),
            'modified' => date('d-m-Y H:i:s', filemtime($source['path']) ?: 0),
            'readable' => is_readable($source['path']),
        ];
    }

    /**
     * Read content for a source: filtered matches, incremental bytes since a
     * cursor, or the last N lines. Assumes existence/readability were already
     * checked by the caller. Returns null for an unknown/missing source.
     *
     * @return array{lines: array<int, string>, cursor: int, truncated: bool}|null
     */
    public function read(string $key, int $lines, ?string $filter = null, ?int $after = null): ?array
    {
        $source = $this->find($key);

        if (! $source || ! is_file($source['path'])) {
            return null;
        }

        $path = $source['path'];
        $size = (int) (filesize($path) ?: 0);
        $lines = max(1, min($lines, self::MAX_LINES));

        // Literal (non-regex) filter: last N matching lines.
        if ($filter !== null && $filter !== '') {
            $match = $this->grep($path, $filter, $lines);

            return ['lines' => $match['lines'], 'cursor' => $size, 'truncated' => $match['truncated']];
        }

        // Incremental follow: bytes appended since `after`. If the file is now
        // smaller than the cursor it was rotated/truncated → fall back to tail.
        if ($after !== null && $after <= $size) {
            $result = $this->range($path, $after);

            return ['lines' => $result['lines'], 'cursor' => $size, 'truncated' => $result['truncated']];
        }

        // Initial load (or post-rotation): last N lines.
        $tail = $this->tail($path, $lines);

        return ['lines' => $tail['lines'], 'cursor' => $size, 'truncated' => $tail['truncated']];
    }

    /**
     * Configured sources + php-fpm logs detected per installed version.
     *
     * @return array<int, array{key: string, label: string, group: string, path: string}>
     */
    private function catalog(): array
    {
        return array_merge(config('server.logs', []), $this->phpFpmLogs());
    }

    /**
     * @return array<int, array{key: string, label: string, group: string, path: string}>
     */
    private function phpFpmLogs(): array
    {
        $dir = (string) config('server.php_dir', '/etc/php');

        if (! is_dir($dir)) {
            return [];
        }

        $pattern = (string) config('server.php_fpm_log', '/var/log/php{version}-fpm.log');

        $logs = [];
        foreach (glob($dir.'/*/fpm', GLOB_ONLYDIR) ?: [] as $fpm) {
            $version = basename(dirname($fpm));
            $logs[] = [
                'key' => "php{$version}_fpm",
                'label' => "PHP {$version} FPM",
                'group' => 'php',
                'path' => str_replace('{version}', $version, $pattern),
            ];
        }

        return $logs;
    }

    /**
     * Last N lines, read from the end of the file in chunks (never loads the
     * whole file). `truncated` = there was more content above the window.
     *
     * @return array{lines: array<int, string>, truncated: bool}
     */
    private function tail(string $path, int $lines): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['lines' => [], 'truncated' => false];
        }

        $position = (int) (filesize($path) ?: 0);
        $buffer = '';

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = (int) min(4096, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = ((string) fread($handle, $read)).$buffer;
        }

        fclose($handle);

        $all = $this->split($buffer);
        $truncated = $position > 0;

        if (count($all) > $lines) {
            $all = array_slice($all, -$lines);
            $truncated = true;
        }

        return ['lines' => $all, 'truncated' => $truncated];
    }

    /**
     * All content from byte `offset` to EOF, capped to MAX_LINES.
     *
     * @return array{lines: array<int, string>, truncated: bool}
     */
    private function range(string $path, int $offset): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['lines' => [], 'truncated' => false];
        }

        fseek($handle, $offset);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        $lines = $this->split($content);
        $truncated = false;

        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, -self::MAX_LINES);
            $truncated = true;
        }

        return ['lines' => $lines, 'truncated' => $truncated];
    }

    /**
     * Last N lines containing `needle` (case-insensitive, literal — not a
     * regex, so no ReDoS/injection). Streams line by line.
     *
     * @return array{lines: array<int, string>, truncated: bool}
     */
    private function grep(string $path, string $needle, int $lines): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['lines' => [], 'truncated' => false];
        }

        $matches = [];
        $truncated = false;

        while (($line = fgets($handle)) !== false) {
            if (stripos($line, $needle) !== false) {
                $matches[] = rtrim($line, "\r\n");

                if (count($matches) > $lines) {
                    array_shift($matches);
                    $truncated = true;
                }
            }
        }

        fclose($handle);

        return ['lines' => $matches, 'truncated' => $truncated];
    }

    /**
     * @return array<int, string>
     */
    private function split(string $content): array
    {
        $content = rtrim(str_replace("\r\n", "\n", $content), "\n");

        return $content === '' ? [] : explode("\n", $content);
    }
}
