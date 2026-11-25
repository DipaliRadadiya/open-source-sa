<?php

namespace App\Services\Server\Backups;

use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;

/**
 * Everything one backup run carries between its steps.
 *
 * Mutable on purpose: each step produces what the next consumes (dump, archive, upload key).
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

    /** The site being backed up. Only for a site's target — see {@see self::isDatabaseTarget()}. */
    public function application(): Application
    {
        return $this->target->application;
    }

    /** A database on its own: only its dump is taken, uploaded under `databases/`. */
    public function isDatabaseTarget(): bool
    {
        return $this->target->isDatabaseTarget();
    }

    public function database(): ?Database
    {
        return $this->target->database;
    }

    /**
     * Who this run is about, for the ops log: the site or the database.
     *
     * @return array<string, int|null>
     */
    public function logSubject(): array
    {
        return $this->isDatabaseTarget()
            ? ['database' => $this->target->database_id]
            : ['application' => $this->target->application_id];
    }

    /**
     * Records a local file for cleanup. Every path a step creates goes through here.
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

    /**
     * A container site's volumes — the only part of it that cannot be recreated.
     *
     * `config` is deliberately excluded so a config-only backup stays small.
     */
    public function wantsVolumes(): bool
    {
        return in_array($this->target->type->value, ['volumes', 'volumes_config'], true);
    }

    /**
     * Its compose files and `.env`.
     *
     * They live in the document root, so `ArchiveFiles` packs them; this says whether it should
     * for a container type, where `wantsFiles()` is false.
     */
    public function wantsConfig(): bool
    {
        return in_array($this->target->type->value, ['config', 'volumes_config'], true);
    }
}
