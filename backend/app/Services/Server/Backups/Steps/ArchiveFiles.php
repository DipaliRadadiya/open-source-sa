<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\Compression\ArchiveCompressor;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Archives the application's files and its database dumps into one compressed artefact.
 *
 * One artefact, not one per part: a half-uploaded backup restores to a broken site.
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
        // Runs for database-only targets too: the dumps still need packing.
        return true;
    }

    public function run(BackupContext $context): void
    {
        $archive = $context->track($context->workingDirectory.'/backup.tar.gz');

        // `--use-compress-program`, not `-z`: `-z` is single-core gzip level 6, far too slow on
        // big sites. See `server.backups.compressor`. The output stays ordinary gzip, so
        // `tar -tzf`, the verify step and existing archives keep working.
        $command = ['tar', '--use-compress-program='.$this->compressor->program('server.backups'), '-cf', $archive];

        // `wantsConfig()` too: a container site's compose files and `.env` live in its root,
        // so "back up the config" is the same tar of the same directory.
        if (! $context->isDatabaseTarget() && ($context->wantsFiles() || $context->wantsConfig())) {
            // The application's own directory, not the served one: {@see BackupRoot}. The kind
            // goes in the manifest because restore uses it; a mismatch unpacks over the wrong directory.
            ['path' => $siteRoot, 'kind' => $kind] = $this->roots->toArchive($context->application());

            if (! is_dir($siteRoot)) {
                throw new RuntimeException("site directory {$siteRoot} does not exist");
            }

            $context->manifest['root_kind'] = $kind;

            // Excludes must not reach the database dumps: tar applies every --exclude to every
            // member, so an unanchored "*.sql" would drop the backup's own dump. Anchoring under
            // the site directory (tar's `*` crosses `/`) keeps them off the dumps at the archive root.
            $excludes = self::excludes($context);
            if ($excludes !== []) {
                $command[] = '--anchored';
                foreach ($excludes as $exclude) {
                    // One argv element each: spaces or shell metacharacters stay data.
                    $command[] = '--exclude='.basename($siteRoot).'/'.$exclude;
                    $command[] = '--exclude='.basename($siteRoot).'/*/'.$exclude;
                }
                $command[] = '--no-anchored';
            }

            // Stored so a restore knows what was left out and does not delete it from the site.
            $context->manifest['file_excludes'] = $excludes;

            // -C keeps paths relative; absolute paths would restore over the original location.
            $command[] = '-C';
            $command[] = dirname($siteRoot);
            $command[] = basename($siteRoot);
        }

        // Database dumps ride in the same archive.
        foreach ($context->localArtifacts as $artifact) {
            if (str_ends_with($artifact, '.sql')) {
                $command[] = '-C';
                $command[] = dirname($artifact);
                $command[] = basename($artifact);
            }
        }

        // Volume tars go in as one directory, which preserves the `volumes/<name>.tar` paths
        // that `ArchiveVolumes` wrote into the manifest.
        if (is_dir($context->workingDirectory.'/volumes')) {
            $command[] = '-C';
            $command[] = $context->workingDirectory;
            $command[] = 'volumes';
        }

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'backup', 'op' => 'archive', ...$context->logSubject()],
            // The job's own ceiling: a separate, shorter step timeout would kill a long archive
            // even though the job is allowed to run longer.
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
