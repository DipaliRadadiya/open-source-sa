<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Archives the application's files, and the database dumps alongside them, into
 * one compressed artefact.
 *
 * One artefact rather than one per part: a backup that is several objects can
 * be half-uploaded, and half a backup restores to a broken site. A single
 * tarball either arrives or does not.
 */
class ArchiveFiles implements BackupStep
{
    public function __construct(
        private ServerOps $serverOps,
        private BackupRoot $roots,
    ) {}

    public function key(): string
    {
        return 'archive_files';
    }

    public function appliesTo(BackupContext $context): bool
    {
        // Runs even for a database-only target: the dumps still need packing
        // into the single artefact that gets uploaded.
        return true;
    }

    /**
     * The compression command tar should pipe through.
     *
     * Resolved per archive rather than once at boot: `pigz` can be installed
     * on a running panel, and an operator who apt-installs it should get the
     * benefit on the next backup rather than after a restart.
     *
     * Falls back to `gzip` whenever pigz is absent, which is the load-bearing
     * part. The updater ships code, never packages — so a build that *required*
     * pigz would break every existing install the moment it upgraded, while
     * working perfectly on every fresh one. The same trap
     * `install.sh changes never reach existing installs` records.
     */
    private function compressor(): string
    {
        $level = (int) config('server.backups.compression_level', 1);

        // Clamp rather than trust: gzip and pigz both reject anything outside
        // 1-9, and a typo in an env file should not fail every backup on the
        // box with a message about command-line syntax.
        $level = max(1, min(9, $level));

        return $this->compressorBinary().' -'.$level;
    }

    /**
     * `pigz` if the configuration allows it and the binary is really there.
     */
    private function compressorBinary(): string
    {
        $configured = (string) config('server.backups.compressor', 'auto');

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
     * backup, and spawning a process to ask whether we can spawn a process is
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

    public function run(BackupContext $context): void
    {
        $archive = $context->track($context->workingDirectory.'/backup.tar.gz');

        // `--use-compress-program` rather than `-z`, so the compressor and its
        // level are ours to choose. `-z` hardcodes gzip at level 6 on one core,
        // which on a 103 GB site measured 67 minutes with seven of eight cores
        // idle — for a 0.54% saving. See `server.backups.compressor`.
        //
        // The artefact is unchanged: pigz emits ordinary gzip, so `tar -tzf`,
        // the verify step and every archive already in a bucket keep working.
        // Confirmed against GNU tar 1.35 before shipping.
        $command = ['tar', '--use-compress-program='.$this->compressor(), '-cf', $archive];

        foreach ((array) ($context->target->file_excludes ?? []) as $exclude) {
            // Passed as its own argv element, so a pattern containing a space
            // or a shell metacharacter is data rather than syntax.
            $command[] = '--exclude='.$exclude;
        }

        if ($context->wantsFiles()) {
            // The application's own directory, not the served one — see
            // {@see BackupRoot}. The kind is recorded in the same breath,
            // because the restore reads it back to know what this archive
            // holds, and an archive whose manifest disagrees with its contents
            // unpacks over the wrong directory.
            ['path' => $siteRoot, 'kind' => $kind] = $this->roots->toArchive($context->application());

            if (! is_dir($siteRoot)) {
                throw new RuntimeException("site directory {$siteRoot} does not exist");
            }

            $context->manifest['root_kind'] = $kind;

            // -C so the archive holds relative paths. An archive of absolute
            // paths restores over the original location no matter where you
            // unpack it, which is precisely what a restore must not do.
            $command[] = '-C';
            $command[] = dirname($siteRoot);
            $command[] = basename($siteRoot);
        }

        // Database dumps ride in the same archive, under a fixed directory the
        // restore knows to look in.
        foreach ($context->localArtifacts as $artifact) {
            if (str_ends_with($artifact, '.sql')) {
                $command[] = '-C';
                $command[] = dirname($artifact);
                $command[] = basename($artifact);
            }
        }

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'backup', 'op' => 'archive', 'application' => $context->application()->id],
            // The job's own ceiling, not a second hardcoded hour. This was
            // `3600`, and it is a trap the raised job timeout does not cover:
            // a 103 GB site takes ~67 minutes to archive with plain gzip, so
            // the *step* was killed at the hour mark even once the *job* was
            // allowed six. Two independent hours, and fixing one left the
            // other to fail the same backup a minute later.
            timeout: (int) config('server.backups.job_timeout', 21600),
        );

        if ($result->failed() || ! is_file($archive)) {
            throw new RuntimeException('could not create the backup archive');
        }

        $context->archivePath = $archive;
        $context->sizeBytes = (int) filesize($archive);
        $context->manifest['archive_bytes'] = $context->sizeBytes;
    }

    public function cleanup(BackupContext $context): void
    {
        // Tracked; the runner removes it.
    }
}
