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
     * `RunFileArchive::failed()` closes a dying job out, but a worker killed
     * outright — OOM, `systemctl kill`, a reboot — never reaches it, and the
     * row is stranded in flight. Because the unique lock refuses a second job
     * for the same target, a stranded row would otherwise mean that path could
     * never be compressed again.
     *
     * The bound is the job's own timeout plus the grace {@see ExpiresUniqueLock}
     * uses, so the lock and the row cannot disagree about whether work is
     * alive. Measured from `started_at` when there is one and `created_at`
     * otherwise — a job dispatched while the queue was down never starts at
     * all, and that is one of the ways a row gets stranded.
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
     * The localized sentence for a failure, in the *viewer's* locale rather
     * than the locale of whoever started the work — the same rule the activity
     * log, runtime installs and database exports all follow, and the reason
     * `reason` is a stored code instead of a finished string.
     */
    public function message(): ?string
    {
        if ($this->status !== FileArchiveStatus::Failed) {
            return null;
        }

        return __('errors/application.archive_failed.'.($this->reason ?: 'unknown'));
    }
}
