<?php

namespace App\Services\Server\Restores;

use App\Models\Application;
use App\Models\Backup;
use App\Models\Restore;

/**
 * Everything one restore carries between its steps.
 */
class RestoreContext
{
    /** Local files produced by this run, deleted on cleanup whatever happens. */
    /** @var list<string> */
    public array $localArtifacts = [];

    /** The downloaded archive. */
    public ?string $archivePath = null;

    /** Where the archive was unpacked — never the live site directory. */
    public ?string $stagingDirectory = null;

    /** Where the previous site directory was moved to, once swapped. */
    public ?string $rollbackPath = null;

    /** Whether this run stopped the application's process and owes it a start. */
    public bool $processStopped = false;

    public function __construct(
        public readonly Restore $restore,
        public readonly Backup $backup,
        public readonly Application $application,
        /** Working directory for this run's local artefacts. */
        public readonly string $workingDirectory,
    ) {}

    public function track(string $path): string
    {
        $this->localArtifacts[] = $path;

        return $path;
    }

    public function wantsDatabase(): bool
    {
        return in_array($this->restore->type->value, ['database', 'full'], true);
    }

    public function wantsFiles(): bool
    {
        return in_array($this->restore->type->value, ['filesystem', 'full'], true);
    }
}
