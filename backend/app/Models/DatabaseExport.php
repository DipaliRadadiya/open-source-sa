<?php

namespace App\Models;

use App\Enums\ExportStatus;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\RunDatabaseExport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'database_id', 'database_name', 'engine', 'file', 'status',
    'reason', 'reference', 'size_bytes', 'user_id', 'started_at', 'finished_at',
])]
class DatabaseExport extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Statuses that mean a dump is supposed to be happening right now.
     *
     * @var array<int, string>
     */
    public const IN_FLIGHT = [
        ExportStatus::Queued->value,
        ExportStatus::Running->value,
    ];

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /** @param  Builder<DatabaseExport>  $query */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', self::IN_FLIGHT);
    }

    /**
     * Whether this row is old enough that nothing can still be working on it.
     *
     * `RunDatabaseExport::failed()` normally closes a dying job out, but a
     * worker killed outright — `kill -9`, an OOM, a reboot — never reaches it,
     * and the row is stranded in flight. That matters now that starting an
     * export refuses while one is running: without this, one stranded row would
     * mean that database could never be exported again, which is precisely the
     * failure the backup feature shipped with.
     *
     * The bound is the job's own timeout plus the grace {@see ExpiresUniqueLock}
     * uses for the queue lock, so the lock and the row cannot disagree about
     * whether a dump is alive. Measured from `started_at` when there is one and
     * `created_at` otherwise — a job dispatched while the queue was down never
     * starts at all, and that is one of the ways a row gets stranded.
     */
    public function isStale(): bool
    {
        if (! in_array($this->status->value, self::IN_FLIGHT, true)) {
            return false;
        }

        $from = $this->started_at ?? $this->created_at;

        return $from !== null
            && $from->addSeconds((new RunDatabaseExport(0, 0))->uniqueFor())->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The localized sentence for a failure, in the *viewer's* locale rather
     * than the locale of whoever started the export — the same rule the
     * activity log and runtime installs follow, and why `reason` is a stored
     * code instead of a finished string.
     */
    public function message(): ?string
    {
        if ($this->status !== ExportStatus::Failed) {
            return null;
        }

        $key = 'database.export_failed.'.($this->reason ?: 'unknown');

        // Falls back to the generic sentence rather than rendering the key at
        // the user when a new reason code has no translation yet.
        return __($key) === $key ? __('database.export_failed.unknown') : __($key);
    }

    /**
     * Whether the file this row describes is still on disk.
     *
     * Asked rather than assumed: someone can delete an export by hand, and a
     * list offering a download that 404s is worse than one that says the file
     * has gone.
     */
    public function fileExists(): bool
    {
        if ($this->file === null) {
            return false;
        }

        return is_file(rtrim((string) config('server.databases.export_dir'), '/').'/'.$this->file);
    }
}
