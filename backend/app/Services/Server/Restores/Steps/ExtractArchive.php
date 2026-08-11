<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Restores\RestoreContext;
use App\Services\Server\ServerOps;
use RuntimeException;

/**
 * Unpacks the archive **beside** the live site, never over it.
 *
 * The obvious implementation — empty the site directory, untar into it — means
 * a failure halfway through leaves the customer with no site at all, and the
 * failure modes here (out of disk, corrupt member, killed worker) are exactly
 * the ones that strike halfway. Extracting to a sibling directory and swapping
 * later costs one rename and makes every failure before the swap invisible to
 * visitors.
 */
class ExtractArchive implements RestoreStep
{
    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
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

        $siteRoot = $this->provisioner->documentRoot($context->application);
        $staging = dirname($siteRoot).'/.restore-'.$context->restore->id;

        // Both through ServerOps rather than File::, which is PHP's own
        // mkdir()/rmdir() and therefore runs as the panel's queue worker. The
        // staging directory sits *beside the live site*, inside a tree owned
        // by that site's own Linux user, and the worker has no access there —
        // it failed with a bare "mkdir(): Permission denied" that named
        // neither the path nor the reason. Every other filesystem action in
        // this class already goes through ServerOps; these two were the
        // exception.
        $this->serverOps->run(
            ['rm', '-rf', $staging],
            ['feature' => 'backup', 'op' => 'restore_staging_reset', 'application' => $context->application->id],
        );

        $created = $this->serverOps->run(
            ['mkdir', '-p', $staging],
            ['feature' => 'backup', 'op' => 'restore_staging_create', 'application' => $context->application->id],
        );

        if ($created->failed()) {
            throw new RuntimeException('the staging directory could not be created');
        }

        // Recorded only once it exists, so a failure above leaves the runner
        // with nothing to clean up rather than a path that was never made.
        $context->stagingDirectory = $staging;

        $command = ['tar', '-xzf', $archive, '-C', $staging];

        if (! $context->wantsFiles()) {
            // Database-only: unpack just the dumps. Unpacking a multi-gigabyte
            // site to read a 40 MB SQL file next to it wastes the disk the
            // restore itself needs.
            $command[] = '--wildcards';
            $command[] = 'db-*.sql';
        }

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'backup', 'op' => 'restore_extract', 'application' => $context->application->id],
            timeout: 3600,
        );

        if ($result->failed()) {
            throw new RuntimeException('the archive could not be extracted');
        }

        if ($context->wantsFiles() && ! is_dir($staging.'/'.basename($siteRoot))) {
            // The archive holds the site under its directory name. If that is
            // missing, this artefact is not what we think it is — better to
            // stop than to swap an empty directory over a working site.
            throw new RuntimeException(
                'the archive does not contain the site directory '.basename($siteRoot),
            );
        }
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // The runner removes the staging directory either way: on success its
        // contents have been renamed into place, on failure it is a partial
        // copy nobody wants sitting next to a live site.
    }
}
