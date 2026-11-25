<?php

namespace App\Models;

use App\Enums\FileArchiveStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\RunFileArchive;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id', 'operation', 'sources', 'target', 'status',
    'reason', 'reference', 'size_bytes', 'user_id', 'started_at', 'finished_at',
])]
class FileArchiveJob extends Model
{
    protected function casts(): array
    {
        return [
            'status' => FileArchiveStatus::class,
            'sources' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Statuses that mean work is supposed to be happening right now.
     *
     * @var array<int, string>
     */
    public const IN_FLIGHT = [
        FileArchiveStatus::Queued->value,
        FileArchiveStatus::Running->value,
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<FileArchiveJob>  $query */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', self::IN_FLIGHT);
    }

    /**
     * Whether this row is old enough that nothing can still be working on it.
     *
     * A worker killed outright (OOM, reboot) never reaches `RunFileArchive::failed()`, and the
     * unique lock would then refuse any new job for that target forever.
     *
     * The bound is the job's timeout plus the grace {@see ExpiresUniqueLock} uses, so lock and
     * row agree. Measured from `started_at`, else `created_at` (a job may never have started).
     */
    public function isStale(): bool
    {
        if (! in_array($this->status->value, self::IN_FLIGHT, true)) {
            return false;
        }

        $from = $this->started_at ?? $this->created_at;

        return $from !== null
            && $from->addSeconds((new RunFileArchive(0))->uniqueFor())->isPast();
    }

    /**
     * The localized sentence for a failure, in the *viewer's* locale (hence `reason` is a
     * stored code, not a finished string).
     */
    public function message(): ?string
    {
        if ($this->status !== FileArchiveStatus::Failed) {
            return null;
        }

        return __('errors/application.archive_failed.'.($this->reason ?: 'unknown'));
    }
}
