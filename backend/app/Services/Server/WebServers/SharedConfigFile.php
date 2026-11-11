<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;

/**
 * One server-wide file the panel owns beside the sites: the catch-all, the
 * Cloudflare real-IP list, nginx's compression settings.
 *
 * Written only when it differs, tested before the reload, and put back
 * exactly as it was when the test fails, so a server the web server refuses
 * it on keeps serving what it had.
 */
class SharedConfigFile
{
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
        $driver = $this->webServers->driver();
        $previous = $this->files->get($path, $context);
        $linked = $enabled === null
            || $this->serverOps->run(['test', '-L', $enabled], $context)->ok;

        if ($previous->ok && $previous->output() === $contents && $linked) {
            return ['status' => 'current', 'reference' => null];
        }

        $written = $this->files->put($path, $contents, $context);

        if ($written->ok && $enabled !== null) {
            $written = $this->files->symlink($path, $enabled, $context);
        }

        $test = $written->ok ? $driver->test() : $written;

        if ($test->failed()) {
            // Back exactly as it was: the previous file if there was one,
            // nothing at all if there was not.
            if ($previous->ok) {
                $this->files->put($path, $previous->output(), $context);

                if (! $linked) {
                    $this->files->delete($enabled, $context);
                }
            } else {
                if ($enabled !== null) {
                    $this->files->delete($enabled, $context);
                }
                $this->files->delete($path, $context);
            }

            return ['status' => 'failed', 'reference' => $test->reference];
        }

        $driver->reload();

        return ['status' => 'updated', 'reference' => null];
    }

    /**
     * Take the file away again, for when the server has come to set the same
     * thing itself. Nothing happens when it is not there.
     *
     * @param  array<string, mixed>  $context
     * @return array{status: 'updated'|'current'|'failed', reference: ?string}
     */
    public function remove(string $path, ?string $enabled, array $context): array
    {
        $previous = $this->files->get($path, $context);

        if (! $previous->ok) {
            return ['status' => 'current', 'reference' => null];
        }

        if ($enabled !== null) {
            $this->files->delete($enabled, $context);
        }
        $this->files->delete($path, $context);

        $driver = $this->webServers->driver();
        $test = $driver->test();

        if ($test->failed()) {
            $this->files->put($path, $previous->output(), $context);

            if ($enabled !== null) {
                $this->files->symlink($path, $enabled, $context);
            }

            return ['status' => 'failed', 'reference' => $test->reference];
        }

        $driver->reload();

        return ['status' => 'updated', 'reference' => null];
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
}
