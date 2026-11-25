<?php

namespace App\Services\Server\Backups;

use App\Models\Application;
use App\Models\Backup;
use App\Services\Server\Applications\ApplicationProvisioner;

/**
 * Which directory a backup is of, and which directory an existing archive came from.
 *
 * Backups used to archive the document root, which for sites with a `web_root` (git-deployed
 * Laravel serving `public`, Craft, Statamic) is a subdirectory, so the archive lacked the
 * application code and `.env`. New backups archive the application's own directory.
 *
 * That changes the archive's top-level entry, which the restore asserts on before swapping, so
 * the format is recorded on the backup as `manifest['root_kind']`. No key means an older archive
 * made from the document root.
 *
 * A kind, not a path: a recorded absolute path goes stale when the slug or home changes. The kind
 * is resolved against the application as it is today.
 *
 * One class so the archive step and both restore steps always agree.
 */
class BackupRoot
{
    /**
     * The application's own directory — what new backups archive.
     */
    public const APPLICATION = 'application';

    /**
     * The served directory — what older backups archived; assumed when no kind is recorded.
     */
    public const DOCUMENT_ROOT = 'document_root';

    public function __construct(private ApplicationProvisioner $provisioner) {}

    /**
     * The directory to archive, and the kind to record beside it.
     *
     * Returned together: a path recorded with the wrong kind restores into the wrong directory.
     *
     * @return array{path: string, kind: string}
     */
    public function toArchive(Application $application): array
    {
        return [
            'path' => $this->provisioner->applicationRoot($application),
            'kind' => self::APPLICATION,
        ];
    }

    /**
     * The directory this backup's archive was made from, where its contents go back to.
     *
     * @param  array<string, mixed>|null  $manifest  the backup's own manifest
     */
    public function forRestore(Application $application, ?array $manifest): string
    {
        $kind = $manifest['root_kind'] ?? self::DOCUMENT_ROOT;

        return $kind === self::APPLICATION
            ? $this->provisioner->applicationRoot($application)
            : $this->provisioner->documentRoot($application);
    }

    /**
     * The same answer for callers holding the model rather than the manifest.
     */
    public function forBackup(Backup $backup, Application $application): string
    {
        return $this->forRestore($application, $backup->manifest);
    }
}
