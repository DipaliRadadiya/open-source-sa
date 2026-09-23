<?php

namespace App\Services\Server\Compression;

/**
 * Picks the gzip-compatible compressor `tar --use-compress-program` should use.
 *
 * Shared by the backup archive step and the file manager, because the two had
 * no business disagreeing about which compressor exists on the box. It started
 * life inside `ArchiveFiles`; the file manager needed the same three decisions
 * (honour an explicit choice, clamp the level, fall back when pigz is absent)
 * and copying them would have meant two places to fix the next time one of
 * them is wrong.
 *
 * `-z` is what this exists to avoid: it hardcodes gzip at level 6 on a single
 * core. On a 103 GB site that measured 67 minutes with seven of eight cores
 * idle, for a 0.54% size saving — the data was already-compressed media.
 *
 * The artefact does not change. pigz emits ordinary gzip, so `tar -tzf`, the
 * backup verify step and every archive already sitting in a bucket keep
 * working; confirmed against GNU tar 1.35 before this shipped.
 */
class ArchiveCompressor
{
    /**
     * The `--use-compress-program` argument: binary plus level.
     *
     * @param  string  $configKey  the `server.*` group holding `compressor` and
     *                             `compression_level`, so backups and the file
     *                             manager can be tuned apart — a nightly backup
     *                             and a button someone is waiting on do not
     *                             want the same trade-off.
     */
    public function program(string $configKey): string
    {
        $level = (int) config("{$configKey}.compression_level", 1);

        // Clamp rather than trust: gzip and pigz both reject anything outside
        // 1-9, and a typo in an env file should not fail every backup on the
        // box with a message about command-line syntax.
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
            // Explicitly demanded. Honour it even if the lookup below fails —
            // an operator who set this deserves the real error from tar rather
            // than a silent downgrade that leaves them wondering why the
            // backup is still slow.
            return 'pigz';
        }

        return $this->onPath('pigz') ? 'pigz' : 'gzip';
    }

    /**
     * Is this binary reachable?
     *
     * Walked by hand rather than shelled out to `which`: this runs once per
     * archive, and spawning a process to ask whether we can spawn a process is
     * the kind of thing that works until a box has an empty PATH.
     *
     * The fallback list matters because tar runs under `sudo`, whose
     * `secure_path` is not the panel user's PATH.
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
