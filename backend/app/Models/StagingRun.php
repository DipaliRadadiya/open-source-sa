<?php

namespace App\Models;

use App\Jobs\RunStagingSync;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staging push or update-from-live, run on the queue (RC-16).
 */
#[Fillable([
    'application_id', 'staging_application_id', 'kind', 'mode', 'backup', 'status',
    'error', 'error_status', 'backup_id', 'user_id', 'started_at', 'finished_at',
])]
class StagingRun extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'backup' => 'boolean',
            'error' => 'array',
            'error_status' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Queued or running, and young enough that its job can still be alive.
     *
     * A worker killed outright never reaches `failed()`, and a row stranded at
     * `running` would block every later push of the site. Past the job's own
     * timeout plus the unique-lock grace, nothing can still be working on it.
     *
     * @param  Builder<StagingRun>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::RUNNING])
            ->where('created_at', '>=', now()->subSeconds(self::staleAfterSeconds()));
    }

    public static function staleAfterSeconds(): int
    {
        return RunStagingSync::timeoutSeconds() + RunStagingSync::UNIQUE_LOCK_GRACE;
    }

    /** Whether a push or pull touching this live site — or its staging copy — is under way. */
    public static function activeFor(Application $production): bool
    {
        return self::query()->active()->where('application_id', $production->id)->exists();
    }

    public function isStranded(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true)
            && $this->created_at !== null
            && $this->created_at->lt(now()->subSeconds(self::staleAfterSeconds()));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function backupRecord(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }
}
