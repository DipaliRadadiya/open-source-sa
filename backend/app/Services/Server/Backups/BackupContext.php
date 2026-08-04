<?php

namespace App\Services\Server\Backups;

use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;

/**
 * Everything one backup run carries between its steps.
 *
 * A mutable object rather than threading half a dozen values through each
 * step's signature: the steps genuinely accumulate state — the dump produces
 * a path the archive consumes, the archive produces a path the upload
 * consumes, the upload produces a key the verify consumes.
 */
class BackupContext
{
    /** Local files produced by this run, deleted on cleanup whatever happens. */
    /** @var list<string> */
    public array $localArtifacts = [];

    /** Written into the backup's manifest — what exists on the destination. */
    /** @var array<string, mixed> */
    public array $manifest = [];

    public ?string $databaseDumpPath = null;

    public ?string $archivePath = null;

    /** Key of the uploaded object, relative to the destination's prefix. */
    public ?string $remoteKey = null;

    public int $sizeBytes = 0;

    public function __construct(
        public readonly Backup $backup,
        public readonly BackupTarget $target,
        /** Working directory for this run's local artefacts. */
        public readonly string $workingDirectory,
    ) {}

    public function application(): Application
    {
        return $this->target->application;
    }

    /**
     * Records a local file for cleanup. Every path a step creates goes through
     * here — a dump left behind after a failed upload fills the disk the next
     * attempt needs.
     */
    public function track(string $path): string
    {
        $this->localArtifacts[] = $path;

        return $path;
    }

    public function wantsDatabase(): bool
    {
        return in_array($this->target->type->value, ['database', 'full'], true);
    }

    public function wantsFiles(): bool
    {
        return in_array($this->target->type->value, ['filesystem', 'full'], true);
    }
}
