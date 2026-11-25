<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Services\Server\Backups\Downloads\PreparedDownloads;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['backup_target_id', 'application_id', 'database_id', 'subject_name', 'subject_engine', 'user_id', 'type', 'is_safety', 'status', 'manifest', 'reason', 'stopped_step', 'stopped_cause', 'error_class', 'reference', 'log_key', 'size_bytes', 'started_at', 'finished_at', 'verified_at', 'worker_pid', 'worker_started'])]
class Backup extends Model
{
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'is_safety' => 'boolean',
            'status' => BackupStatus::class,
            'manifest' => 'array',
            'size_bytes' => 'integer',
            'worker_pid' => 'integer',
            'bytes_transferred' => 'integer',
            'bytes_total' => 'integer',
            'progress_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Assigns the archive's name (uid) on the storage destination.
     *
     * Set here, as in {@see Worker::booted()}, so no creation site can forget it. Not fillable:
     * it keeps one backup's archive from overwriting another's, and a counter id would collide
     * after a panel reinstall over a bucket that still holds old archives.
     */
    protected static function booted(): void
    {
        static::creating(function (self $backup): void {
            if (($backup->uid ?? '') === '') {
                $backup->uid = (string) Str::uuid();
            }

            // Stamped here, not fillable: a target's destination is editable, so reading it back
            // later would give today's answer about where an old archive was written.
            if ($backup->storage_destination_id === null && $backup->backup_target_id !== null) {
                $backup->storage_destination_id = BackupTarget::query()
                    ->whereKey($backup->backup_target_id)
                    ->value('storage_destination_id');
            }
        });

        // Remove the local copy a prepared download left (SFTP/FTP): a cascade deletes the row
        // but not the file. A delete by cascade from further up is swept by
        // `backups:prune-downloads`.
        static::deleting(function (self $backup): void {
            app(PreparedDownloads::class)->forget($backup);
        });
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'backup_target_id');
    }

    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(StorageDestination::class);
    }

    /**
     * Where this archive actually lives.
     *
     * Recorded on the row at upload time: a target's destination is editable, so resolving it
     * through the target would point older backups at a bucket their archive is not in. The
     * fallback covers rows written before that column existed.
     */
    public function destination(): ?StorageDestination
    {
        return $this->storageDestination ?? $this->target?->storageDestination;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * Does the archive hold a database dump?
     *
     * Not the same as the type: a `full` backup of a site with no database records
     * `databases: []` in the manifest. A row from before the manifest recorded it answers by type.
     */
    public function holdsDatabase(): bool
    {
        if (! in_array($this->type->value, ['database', 'full'], true)) {
            return false;
        }

        $manifest = (array) ($this->manifest ?? []);

        return ! array_key_exists('databases', $manifest) || $manifest['databases'] !== [];
    }

    /**
     * A backup of a database on its own, still attached or archived after the database was
     * deleted. `subject_engine` is written for these only, so it survives the database going.
     */
    public function isDatabaseBackup(): bool
    {
        return $this->database_id !== null || ($this->application_id === null && $this->subject_engine !== null);
    }

    /**
     * What it was taken from was deleted: the backup stays, listed and
     * downloadable until someone deletes it. A database's can be restored
     * into another database of the same engine; a site's is downloaded.
     */
    public function isArchived(): bool
    {
        // Both owners gone only ever happens by archiving (the database or site was deleted).
        return $this->database_id === null && $this->application_id === null;
    }

    /** The site's name, or the database's — kept on the row, so an archived backup still says what it was. */
    public function subjectName(): string
    {
        return $this->application_id !== null
            ? (string) ($this->application?->name ?? '')
            : (string) ($this->database?->name ?? $this->subject_name ?? '');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function downloadPreparation(): HasOne
    {
        return $this->hasOne(BackupDownloadPreparation::class);
    }

    /**
     * A readable file name for the download (the object key is a uuid). Here so the link and the
     * prepared copy name a file the same way.
     */
    public function downloadFilename(): string
    {
        $site = $this->application?->domain ?? match (true) {
            $this->isDatabaseBackup() => 'database-'.$this->subjectName(),
            // An archived site's backup still carries the domain it was taken of.
            filled($this->subject_name) => (string) $this->subject_name,
            default => 'backup',
        };
        $stamp = $this->created_at?->format('Y-m-d-His') ?? 'unknown';

        // Dots become hyphens first: Str::slug strips them (shop.example.com -> "shopexamplecom").
        return Str::slug(str_replace('.', '-', $site)).'-'.$stamp.'-'.$this->type->value.'.tar.gz';
    }
}
