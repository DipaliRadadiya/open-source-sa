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
    'database_id', 'database_name', 'engine', 'purpose', 'file', 'status',
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
    /** The copy an import takes before it replaces anything (DatabaseImport). */
    public const PURPOSE_IMPORT_SAFETY = 'import_safety';

    public const IN_FLIGHT = [
        ExportStatus::Queued->value,
        ExportStatus::Running->value,
    ];

    /**
     * Remove the dump file, then the row.
     *
     * basename() guards the path, which is built from stored data, at the point of deletion.
     */
    public function deleteWithFile(): void
    {
        if ($this->file !== null) {
            $path = rtrim((string) config('server.databases.export_dir'), '/').'/'.basename($this->file);

            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->delete();
    }

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
     * `RunDatabaseExport::failed()` normally closes a dying job out, but a killed worker (OOM,
     * `kill -9`, reboot) never reaches it, and a stranded in-flight row would block exporting
     * that database again, since starting an export refuses while one runs.
     *
     * The bound is the job's timeout plus the grace {@see ExpiresUniqueLock} uses for the queue
     * lock, so lock and row agree. Measured from `started_at`, else `created_at` (a job
     * dispatched while the queue was down never starts).
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
     * The localized sentence for a failure, in the *viewer's* locale, which is why `reason` is
     * a stored code and not a finished string.
     */
    public function message(): ?string
    {
        if ($this->status !== ExportStatus::Failed) {
            return null;
        }

        $key = 'database.export_failed.'.($this->reason ?: 'unknown');

        // Falls back to the generic sentence when a new reason code has no translation yet.
        return __($key) === $key ? __('database.export_failed.unknown') : __($key);
    }

    /**
     * Whether the file this row describes is still on disk.
     *
     * Asked, not assumed: an export can be deleted by hand, and a download that 404s is worse
     * than saying the file is gone.
     */
    public function fileExists(): bool
    {
        if ($this->file === null) {
            return false;
        }

        return is_file(rtrim((string) config('server.databases.export_dir'), '/').'/'.$this->file);
    }
}
