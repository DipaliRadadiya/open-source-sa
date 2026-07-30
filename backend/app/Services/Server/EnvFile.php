<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Reads and writes single keys in the panel's own `.env`.
 *
 * Needed because some server-side changes are also changes to how the panel
 * connects to that service — set a Redis password and the panel's own
 * `REDIS_PASSWORD` has to move with it, or the next request fails NOAUTH and
 * the screen you would use to fix it is behind the same broken connection.
 *
 * Written atomically. A half-written .env is a panel that will not boot at
 * all, which is worse than any value being wrong.
 */
class EnvFile
{
    public function path(): string
    {
        return app()->environmentFilePath();
    }

    public function writable(): bool
    {
        return is_file($this->path()) && is_writable($this->path());
    }

    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $m) === 1) {
                return $this->unquote(trim($m[1]));
            }
        }

        return null;
    }

    /**
     * Set (or add) a key, preserving every other line — comments, ordering
     * and spacing included. This file is hand-edited by operators; rewriting
     * it wholesale from a template would silently drop their changes.
     *
     * @throws RuntimeException
     */
    public function set(string $key, string $value): void
    {
        $path = $this->path();

        // Callers should check writable() first and refuse before making the
        // change this is meant to record — see RedisSettings, which will not
        // set a password it cannot write down.
        if (! $this->writable()) {
            throw new RuntimeException("{$path} is not writable");
        }

        $quoted = $this->quote($value);
        $lines = $this->lines();
        $found = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                $lines[$index] = "{$key}={$quoted}";
                $found = true;
            }
        }

        if (! $found) {
            $lines[] = "{$key}={$quoted}";
        }

        $contents = implode("\n", $lines);
        $contents = rtrim($contents, "\n")."\n";

        // Write beside the target then rename: rename is atomic on the same
        // filesystem, so a crash mid-write leaves the old file intact rather
        // than a truncated one the app cannot boot from.
        $temporary = $path.'.panel-tmp';

        // Carry the existing mode and ownership across. Imposing a mode here
        // is how the panel locks itself out of its own config: an earlier
        // version forced 0640 on a file that was 0664 and group-writable, so
        // the first write succeeded and every write after it was refused.
        // Whatever the operator set is theirs to keep.
        $mode = is_file($path) ? (fileperms($path) & 0777) : 0640;
        $owner = is_file($path) ? fileowner($path) : null;
        $group = is_file($path) ? filegroup($path) : null;

        try {
            File::put($temporary, $contents);
            @chmod($temporary, $mode);

            if ($owner !== null && $group !== null) {
                // Only possible when we already own the file or are root;
                // failing is fine, the mode above is what keeps it writable.
                @chown($temporary, $owner);
                @chgrp($temporary, $group);
            }

            if (! @rename($temporary, $path)) {
                throw new RuntimeException('rename failed');
            }
        } catch (Throwable $e) {
            @unlink($temporary);

            throw new RuntimeException("could not write {$path}", 0, $e);
        }
    }

    /**
     * @return array<int, string>
     */
    private function lines(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        return preg_split('/\r?\n/', (string) File::get($this->path())) ?: [];
    }

    /**
     * Quote when the value contains anything dotenv would misread. A
     * generated password can hold `#`, spaces or quotes, and an unquoted `#`
     * silently truncates the value into a comment.
     */
    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        return $value;
    }
}
