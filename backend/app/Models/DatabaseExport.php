<?php

namespace App\Models;

use App\Enums\ExportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
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
