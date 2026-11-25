<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\Backups\BackupRoot;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Unpacks the archive **beside** the live site, never over it, so a failure halfway (disk full,
 * corrupt member, killed worker) never leaves the site empty; the swap happens later.
 */
class ExtractArchive implements RestoreStep
{
    public function __construct(
        private ServerOps $serverOps,
        private BackupRoot $roots,
        private SiteRootLock $rootLock,
    ) {}

    public function key(): string
    {
        return 'extract_archive';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return true;
    }

    public function run(RestoreContext $context): void
    {
        $archive = $context->archivePath;

        if ($archive === null || ! is_file($archive)) {
            throw new RuntimeException('there is no archive to extract');
        }

        if ($context->isDatabaseRestore()) {
            $this->extractDatabaseDump($context, $archive);

            return;
        }

        // The directory *this archive* was made from, which may differ from today's (see
        // {@see BackupRoot}). It sets the expected entry name and where staging sits.
        $siteRoot = $this->roots->forBackup($context->backup, $context->application);
        $staging = dirname($siteRoot).'/.restore-'.$context->restore->id;

        // Through ServerOps, not File::: the staging directory sits in a tree owned by the site's own
        // Linux user, where the queue worker has no access. It is created at the top of the site
        // root (see SiteRootLock); extracting into it later changes only its own contents.
        $created = $this->rootLock->unlocked($context->application, function () use ($staging, $context) {
            $this->serverOps->run(
                ['rm', '-rf', $staging],
                ['feature' => 'backup', 'op' => 'restore_staging_reset', 'application' => $context->application->id],
            );

            return $this->serverOps->run(
                ['mkdir', '-p', $staging],
                ['feature' => 'backup', 'op' => 'restore_staging_create', 'application' => $context->application->id],
            );
        });

        if ($created->failed()) {
            throw new RuntimeException('the staging directory could not be created');
        }

        // Recorded only once it exists, so a failure above leaves the runner nothing to clean up.
        $context->stagingDirectory = $staging;

        $command = ['tar', '-xzf', $archive, '-C', $staging];

        // A restore needing the site directory (hosted files, or a container site's compose and
        // `.env`) takes the whole archive; anything else unpacks only the members it reads, to
        // save the disk the restore itself needs.
        if (! $context->wantsFiles() && ! $context->wantsConfig()) {
            $command[] = '--wildcards';
            $command[] = 'db-*.sql';

            if ($context->wantsVolumes()) {
                // Written by `ArchiveVolumes` as `volumes/<name>.tar`.
                $command[] = 'volumes/*';
            }
        }

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'backup', 'op' => 'restore_extract', 'application' => $context->application->id],
            // Extraction scales with the archive (as in ArchiveFiles), so the timeout is generous.
            timeout: (int) config('server.backups.job_timeout', 21600),
        );

        if ($result->failed()) {
            throw new RuntimeException('the archive could not be extracted');
        }

        if (($context->wantsFiles() || $context->wantsConfig()) && ! is_dir($staging.'/'.basename($siteRoot))) {
            // The archive holds the site under its directory name; without it, stop rather than
            // swap an empty directory over a working site.
            throw new RuntimeException(
                'the archive does not contain the site directory '.basename($siteRoot),
            );
        }
    }

    /**
     * A database on its own: only its dump, unpacked in the panel's working directory.
     */
    private function extractDatabaseDump(RestoreContext $context, string $archive): void
    {
        $staging = $context->workingDirectory.'/extract';

        $created = $this->serverOps->run(['mkdir', '-p', $staging], ['feature' => 'backup', 'op' => 'restore_staging_create', ...$context->logSubject()]);

        if ($created->failed()) {
            throw new RuntimeException('the staging directory could not be created');
        }

        $context->stagingDirectory = $staging;

        $result = $this->serverOps->run(
            ['tar', '-xzf', $archive, '-C', $staging, '--wildcards', 'db-*.sql'],
            ['feature' => 'backup', 'op' => 'restore_extract', ...$context->logSubject()],
            timeout: (int) config('server.backups.job_timeout', 21600),
        );

        if ($result->failed()) {
            throw new RuntimeException('the archive could not be extracted');
        }
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // The runner removes the staging directory either way.
    }
}
