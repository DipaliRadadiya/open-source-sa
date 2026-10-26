<?php

namespace App\Models;

use App\Actions\Server\Database\ImportDatabase;
use App\Enums\ImportStatus;
use App\Jobs\RunDatabaseImport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded file being loaded into a database, replacing its contents.
 * {@see ImportDatabase} for what a run does.
 */
#[Fillable([
    'database_id', 'database_name', 'engine', 'original_name', 'size_bytes', 'received_bytes',
    'status', 'step', 'reason', 'reference', 'line', 'safety_export_id', 'safety_copy', 'restored', 'user_id',
    'started_at', 'finished_at',
])]
class DatabaseImport extends Model
{
    /**
     * Mirrors the column default, so a row made in code before it is saved and
     * re-read says what the database will: an import takes its safety copy
     * unless told not to.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['safety_copy' => true];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'size_bytes' => 'integer',
            'received_bytes' => 'integer',
            'line' => 'integer',
            'restored' => 'boolean',
            'safety_copy' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** States in which the database is about to change, or changing. */
    public const IN_FLIGHT = [
        ImportStatus::Queued->value,
        ImportStatus::Running->value,
    ];

    /** Every state that still has an uploaded file on disk waiting for use. */
    public const OPEN = [
        ImportStatus::Uploading->value,
        ImportStatus::Queued->value,
        ImportStatus::Running->value,
    ];

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function safetyExport(): BelongsTo
    {
        return $this->belongsTo(DatabaseExport::class, 'safety_export_id');
    }

    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', self::IN_FLIGHT);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    /**
     * Queued or running past the job's own lock: the worker that held it is
     * gone (killed outright reaches neither the action nor `failed()`), and
     * without this the database could never be imported into again.
     *
     * An upload is stale after a day without a chunk — long enough for any
     * resumed upload, short enough that an abandoned one does not hold disk.
     */
    public function isStale(): bool
    {
        if ($this->status === ImportStatus::Uploading) {
            return $this->updated_at !== null && $this->updated_at->addDay()->isPast();
        }

        if (! in_array($this->status->value, self::IN_FLIGHT, true)) {
            return false;
        }

        $from = $this->started_at ?? $this->updated_at ?? $this->created_at;

        return $from !== null
            && $from->addSeconds((new RunDatabaseImport(0, 0))->uniqueFor())->isPast();
    }

    /**
     * The sentence for a failure, in the viewer's locale; a stored code rather
     * than a finished string, as for exports.
     */
    public function message(): ?string
    {
        if ($this->status !== ImportStatus::Failed) {
            return null;
        }

        $key = 'database.import_failed.'.($this->reason ?: 'unknown');
        $message = __($key, ['line' => $this->line]) === $key
            ? __('database.import_failed.unknown')
            : __($key, ['line' => $this->line]);

        // Said on every failure, because it is the first thing anyone wants to
        // know: is my data still there? Nothing is dropped before `importing`.
        $outcome = match (true) {
            $this->restored => 'restored',
            in_array($this->step, [null, 'checking', 'backing_up'], true) => 'untouched',
            // The user chose to skip the copy: there was nothing to put back,
            // and pointing them at an export that does not exist would be worse.
            ! $this->safety_copy => 'no_copy',
            default => 'not_restored',
        };

        return $message.' '.__('database.import_failed.'.$outcome);
    }
}
