<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;

/**
 * The server-wide files the panel owns beside the sites: the catch-all, the
 * Cloudflare real-IP list, nginx's compression lines.
 *
 * Changed as one set — files written or removed, links made or removed —
 * only when something differs, tested before the reload, and put back exactly
 * as it was when the test fails, so a server the web server refuses it on
 * keeps serving what it had.
 *
 * They sit where v7 has them, under v7's names (Dipali, 2026-11-11), which
 * on a server that comes from v7 is a file v7 or its owner already wrote.
 * {@see self::ownership()} is how the callers tell them apart: a file is the
 * panel's to write only when it is absent, the package's untouched original,
 * or one the panel wrote itself.
 */
class SharedConfigFile
{
    /** First line of every file the panel writes here. */
    public const MARKER = '# Managed by the panel';

    public function __construct(
        private WebServerManager $webServers,
        private ManagedFile $files,
        private ServerOps $serverOps,
    ) {}

    /**
     * @param  string|null  $enabled  The link the web server reads it through (Apache conf-enabled), or null.
     * @param  array<string, mixed>  $context
     * @return array{status: 'updated'|'current'|'failed', reference: ?string}
     */
    public function publish(string $path, string $contents, ?string $enabled, array $context): array
    {
        return $this->apply([$path => $contents], $enabled === null ? [] : [$enabled => $path], $context);
    }

    /**
     * Take the file away again. Nothing happens when it is not there.
     *
     * @param  array<string, mixed>  $context
     * @return array{status: 'updated'|'current'|'failed', reference: ?string}
     */
    public function remove(string $path, ?string $enabled, array $context): array
    {
        return $this->apply([$path => null], $enabled === null ? [] : [$enabled => null], $context);
    }

    /**
     * One change to several files and links, tested once and reloaded once.
     *
     * @param  array<string, string|null>  $files  path => contents, or null to remove it
     * @param  array<string, string|null>  $links  link => target, or null to remove it
     * @param  array<string, mixed>  $context
     * @return array{status: 'updated'|'current'|'failed', reference: ?string}
     */
    public function apply(array $files, array $links, array $context): array
    {
        $before = [];

        foreach (array_keys($files) as $path) {
            $read = $this->files->get($path, $context);
            $before[$path] = $read->ok ? $read->output() : null;
        }

        $linksBefore = [];

        foreach (array_keys($links) as $link) {
            $linksBefore[$link] = $this->linkTarget($link, $context);
        }

        if ($before === $files && $linksBefore === $links) {
            return ['status' => 'current', 'reference' => null];
        }

        $ok = true;
        $reference = null;

        foreach ($files as $path => $contents) {
            if ($contents === $before[$path]) {
                continue;
            }

            $result = $contents === null
                ? $this->files->delete($path, $context)
                : $this->files->put($path, $contents, $context);

            if ($result->failed()) {
                [$ok, $reference] = [false, $result->reference];

                break;
            }
        }

        foreach ($ok ? $links : [] as $link => $target) {
            if ($target === $linksBefore[$link]) {
                continue;
            }

            $result = $target === null
                ? $this->files->delete($link, $context)
                : $this->files->symlink($target, $link, $context);

            if ($result->failed()) {
                [$ok, $reference] = [false, $result->reference];

                break;
            }
        }

        $driver = $this->webServers->driver();

        if ($ok) {
            $test = $driver->test();
            [$ok, $reference] = [$test->ok, $test->reference];
        }

        if (! $ok) {
            // Back exactly as it was: each file's previous text, or no file
            // where there was none, and each link as it pointed.
            foreach ($linksBefore as $link => $target) {
                $target === null
                    ? $this->files->delete($link, $context)
                    : $this->files->symlink($target, $link, $context);
            }

            foreach ($before as $path => $contents) {
                $contents === null
                    ? $this->files->delete($path, $context)
                    : $this->files->put($path, $contents, $context);
            }

            return ['status' => 'failed', 'reference' => $reference];
        }

        $driver->reload();

        return ['status' => 'updated', 'reference' => null];
    }

    /**
     * A file's text, or null when it cannot be read.
     *
     * @param  array<string, mixed>  $context
     */
    public function read(string $path, array $context): ?string
    {
        $read = $this->files->get($path, $context);

        return $read->ok ? $read->output() : null;
    }

    /**
     * Whose file this is.
     *
     *  - `absent`: nothing there;
     *  - `ours`: the panel wrote it (its first line is {@see self::MARKER});
     *  - `stock`: the package's own file, untouched — its md5 is the one
     *    `dpkg` recorded for it as a conffile of `$package`;
     *  - `foreign`: anything else — v7's, or the owner's own. Never written.
     *
     * @param  array<string, mixed>  $context
     * @return 'absent'|'ours'|'stock'|'foreign'
     */
    public function ownership(string $path, ?string $package, array $context): string
    {
        $read = $this->files->get($path, $context);

        if (! $read->ok) {
            // "No such file" is the one failure that means absent; anything
            // else (unreadable, sudo refused) is not known to be ours.
            return str_contains($read->errorOutput(), 'No such file') ? 'absent' : 'foreign';
        }

        if (str_starts_with($read->output(), self::MARKER)) {
            return 'ours';
        }

        if ($package !== null && $this->stockMd5($package, $path, $context) === md5($read->output())) {
            return 'stock';
        }

        return 'foreign';
    }

    /**
     * Where a symlink points, or null when there is no link there. A regular
     * file in its place reads as pointing nowhere, which no caller writes over.
     *
     * @param  array<string, mixed>  $context
     */
    public function linkTarget(string $link, array $context): ?string
    {
        if (! $this->serverOps->run(['test', '-L', $link], $context)->ok) {
            return null;
        }

        // `find -printf %l`: readlink is not on the privilege allowlist, find is.
        $found = $this->serverOps->run(['find', $link, '-maxdepth', '0', '-printf', '%l'], $context);

        return $found->ok ? trim($found->output()) : null;
    }

    /**
     * Whether something that is not a symlink sits at `$link`.
     *
     * @param  array<string, mixed>  $context
     */
    public function occupied(string $link, array $context): bool
    {
        return $this->linkTarget($link, $context) === null
            && $this->serverOps->run(['test', '-e', $link], $context)->ok;
    }

    /**
     * The text of every config file but the ones named: `$files` and every
     * `*.conf` in `$dirs`. Read once, so a caller can ask several questions of
     * it — whether something there already sets a directive the panel would
     * write, which nginx refuses as a duplicate and which, set by the server's
     * owner, is an answer the panel should not overrule. An unreadable file
     * reads as empty.
     *
     * @param  array<int, string>  $files
     * @param  array<int, string>  $dirs
     * @param  array<int, string>  $except
     * @param  array<string, mixed>  $context
     */
    public function otherConfigs(array $files, array $dirs, array $except, array $context): string
    {
        foreach ($dirs as $dir) {
            $found = $this->serverOps->run(['find', $dir, '-maxdepth', '1', '-name', '*.conf'], $context);

            if ($found->ok) {
                $files = array_merge($files, preg_split('/\r?\n/', trim($found->output())) ?: []);
            }
        }

        $text = '';

        foreach (array_unique(array_filter($files)) as $file) {
            if (in_array($file, $except, true)) {
                continue;
            }

            $read = $this->files->get($file, $context);

            if ($read->ok) {
                $text .= $read->output()."\n";
            }
        }

        return $text;
    }

    /**
     * The md5 dpkg recorded for `$path` as one of `$package`'s conffiles.
     *
     * @param  array<string, mixed>  $context
     */
    private function stockMd5(string $package, string $path, array $context): ?string
    {
        $result = $this->serverOps->run(['dpkg-query', '-W', '-f=${Conffiles}\n', $package], $context);

        if ($result->failed()) {
            return null;
        }

        foreach (preg_split('/\r?\n/', $result->output()) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (($parts[0] ?? null) === $path && isset($parts[1]) && preg_match('/^[0-9a-f]{32}$/', $parts[1]) === 1) {
                return $parts[1];
            }
        }

        return null;
    }
}
