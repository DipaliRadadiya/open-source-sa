<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Backups\BackupContext;
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
        private ApplicationProvisioner $provisioner,
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

        $command = ['tar', '-czf', $archive];

        foreach ((array) ($context->target->file_excludes ?? []) as $exclude) {
            // Passed as its own argv element, so a pattern containing a space
            // or a shell metacharacter is data rather than syntax.
            $command[] = '--exclude='.$exclude;
        }

        if ($context->wantsFiles()) {
            $siteRoot = $this->provisioner->documentRoot($context->application());

            if (! is_dir($siteRoot)) {
                throw new RuntimeException("site directory {$siteRoot} does not exist");
            }

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
            timeout: 3600,
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
