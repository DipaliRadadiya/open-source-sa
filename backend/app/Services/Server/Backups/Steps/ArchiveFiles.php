<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\Compression\ArchiveCompressor;
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
        private ArchiveCompressor $compressor = new ArchiveCompressor,
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
        $command = ['tar', '--use-compress-program='.$this->compressor->program('server.backups'), '-cf', $archive];

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

            // The excludes apply to the site's files only, never to the
            // database dumps packed beside them. tar applies every --exclude to
            // every member, so a "*.sql" meant for stray dumps in the web root
            // also dropped the backup's own database dump, and the backup still
            // finished as complete (bug #77: "Files and database" with no
            // database). Anchored under the site's own directory -- at its top
            // or at any depth below it, since tar's `*` crosses `/` -- they
            // cannot reach the dumps, which sit at the archive's root.
            $excludes = self::excludes($context);
            if ($excludes !== []) {
                $command[] = '--anchored';
                foreach ($excludes as $exclude) {
                    // Each its own argv element: a space or a shell
                    // metacharacter in a pattern is data, not syntax.
                    $command[] = '--exclude='.basename($siteRoot).'/'.$exclude;
                    $command[] = '--exclude='.basename($siteRoot).'/*/'.$exclude;
                }
                $command[] = '--no-anchored';
            }

            // Kept with the backup, so a restore knows what this archive
            // deliberately left out and does not delete it from the site
            // (bug #78).
            $context->manifest['file_excludes'] = $excludes;

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

    /**
     * The target's exclude patterns, relative to the site's directory.
     * A leading "/" or "./" meant the same thing to the user and is dropped.
     *
     * @return array<int, string>
     */
    public static function excludes(BackupContext $context): array
    {
        return array_values(array_filter(array_map(
            fn ($pattern) => ltrim(preg_replace('#^\./#', '', trim((string) $pattern)) ?? '', '/'),
            (array) ($context->target->file_excludes ?? []),
        ), fn (string $pattern) => $pattern !== ''));
    }

    public function cleanup(BackupContext $context): void
    {
        // Tracked; the runner removes it.
    }
}
