<?php

namespace App\Services\Server\Backups;

use App\Models\Application;
use App\Models\Backup;
use App\Services\Server\Applications\ApplicationProvisioner;

/**
 * Which directory a backup is of — and, for an archive already written, which
 * directory it came from.
 *
 * Backups used to archive the **document root**, which is the same directory as
 * the application for most site types and a subdirectory of it for the rest:
 * anything with a `web_root` (a git-deployed Laravel serving `public`) and
 * Craft and Statamic, where the type fixes one. For those sites the archive
 * held the public folder and nothing above it — no application code, no `.env`,
 * no vendor — and it restored cleanly, which is the worst way for a backup to
 * be wrong. It is the application's own directory that is being backed up, so
 * that is what is archived now.
 *
 * Changing that changes the archive's top-level entry, from `public` to
 * `public_html`, and the restore asserts on that name before it swaps anything.
 * So the format is **recorded on the backup**, not inferred from the site:
 * `manifest['root_kind']`, set when the archive is written and read back when
 * it is restored. An archive taken before this existed has no key, which is
 * itself the answer — it was made from the document root.
 *
 * A *kind* rather than the path, deliberately. An absolute path recorded at
 * backup time stops being true the moment the site's slug or its user's home
 * changes, and a restore would then unpack a real archive over a directory
 * nobody is serving. The kind is resolved against the application as it is
 * today, which is the only version of it that can be restored onto.
 *
 * One class, because the rule is a compatibility rule: the archive step and the
 * two restore steps have to agree about it forever, and three copies of a
 * three-line conditional is how they stop agreeing.
 */
class BackupRoot
{
    /**
     * The application's own directory — what new backups archive.
     */
    public const APPLICATION = 'application';

    /**
     * The served directory — what backups taken before this archived, and what
     * an archive with no recorded kind is therefore assumed to hold.
     */
    public const DOCUMENT_ROOT = 'document_root';

    public function __construct(private ApplicationProvisioner $provisioner) {}

    /**
     * The directory to archive, and the kind to record beside it.
     *
     * Returned together so a caller cannot write one without the other: an
     * archive of the application recorded as a document root restores into the
     * wrong directory, and the two values are only correct as a pair.
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
     * The directory this backup's archive was made from, which is where its
     * contents belong when they go back.
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
     * The same answer for a backup, for callers holding the model rather than
     * the manifest array.
     */
    public function forBackup(Backup $backup, Application $application): string
    {
        return $this->forRestore($application, $backup->manifest);
    }
}
