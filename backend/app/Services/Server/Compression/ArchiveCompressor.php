<?php

namespace App\Services\Server\Compression;

/**
 * Picks the gzip-compatible compressor `tar --use-compress-program` should use.
 *
 * Shared by the backup archive step and the file manager (explicit choice, clamped level, gzip
 * fallback when pigz is absent). `-z` is avoided because it is single-core gzip level 6.
 * pigz emits ordinary gzip, so `tar -tzf`, backup verification and existing archives keep working.
 */
class ArchiveCompressor
{
    /**
     * The `--use-compress-program` argument: binary plus level.
     *
     * @param  string  $configKey  the `server.*` group holding `compressor` and
     *                             `compression_level`, so backups and the file
     *                             manager can be tuned apart
     */
    public function program(string $configKey): string
    {
        $level = (int) config("{$configKey}.compression_level", 1);

        // Clamp: gzip and pigz reject anything outside 1-9, and an env typo must not fail every backup.
        $level = max(1, min(9, $level));

        return $this->binary($configKey).' -'.$level;
    }

    /**
     * `pigz` if the configuration allows it and the binary is really there.
     */
    public function binary(string $configKey): string
    {
        $configured = (string) config("{$configKey}.compressor", 'auto');

        if ($configured === 'gzip') {
            return 'gzip';
        }

        if ($configured === 'pigz') {
            // Explicitly demanded: honour it even if the lookup fails, so tar gives the real error
            // rather than a silent downgrade to gzip.
            return 'pigz';
        }

        return $this->onPath('pigz') ? 'pigz' : 'gzip';
    }

    /**
     * Is this binary reachable?
     *
     * Walked by hand, not `which`: no process spawn, and it works with an empty PATH. The fallback
     * list matters because tar runs under `sudo`, whose `secure_path` is not the panel user's PATH.
     */
    private function onPath(string $binary): bool
    {
        $paths = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $paths = array_merge($paths, ['/usr/local/bin', '/usr/bin', '/bin']);

        foreach (array_unique($paths) as $dir) {
            if (is_executable(rtrim($dir, '/').'/'.$binary)) {
                return true;
            }
        }

        return false;
    }
}
