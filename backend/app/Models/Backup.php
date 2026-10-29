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

#[Fillable(['backup_target_id', 'application_id', 'database_id', 'subject_name', 'subject_engine', 'user_id', 'type', 'is_safety', 'status', 'manifest', 'reason', 'stopped_step', 'stopped_cause', 'error_class', 'reference', 'log_key', 'size_bytes', 'started_at', 'finished_at', 'verified_at'])]
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
            'bytes_transferred' => 'integer',
            'bytes_total' => 'integer',
            'progress_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The archive's name on the storage destination.
     *
     * Set here rather than by the caller, for the reason {@see Worker::booted()}
     * gives: leaving it to callers means every creation site has to remember,
     * and the one that forgets fails at the database instead of anywhere
     * useful. There is only one creation site today; a uid that exists because
     * of where it is assigned rather than because someone remembered is the
     * point.
     *
     * Not fillable, deliberately. This is what stops one backup's archive from
     * overwriting another's, so nothing outside this model gets to choose it —
     * an id would do, right up until the panel is reinstalled and the counter
     * starts again over a bucket that still holds the old archives.
     */
    protected static function booted(): void
    {
        static::creating(function (self $backup): void {
            if (($backup->uid ?? '') === '') {
                $backup->uid = (string) Str::uuid();
            }

            // Stamped here for the same reason as the uid above, and not left
            // fillable: a target's destination is editable, so a backup that
            // reads it back later reads *today's* answer about where a months
            // old archive was written. There is one creation site today; this
            // is what stops the second one from having to remember.
            if ($backup->storage_destination_id === null && $backup->backup_target_id !== null) {
                $backup->storage_destination_id = BackupTarget::query()
                    ->whereKey($backup->backup_target_id)
                    ->value('storage_destination_id');
            }
        });

        // The local copy a prepared download left (SFTP/FTP). Its row goes
        // with the backup by cascade, but a cascade cannot remove a file, and
        // an hour-long copy of a deleted backup is exactly the archive the
        // user just asked to be rid of. Every Eloquent delete passes here —
        // `DeleteBackup` and retention alike; a delete by cascade from
        // further up is swept by `backups:prune-downloads`.
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
     * Recorded on the row when the upload happens, because a target's
     * destination is editable and reaching it through the target moved
     * *history*: repoint a target and every older backup started resolving to
     * a bucket its archive was never in — download said "missing", delete
     * removed the row and orphaned the object, and the list named the wrong
     * provider.
     *
     * The fallback covers a row written before that column existed and never
     * backfilled (its target or destination had already gone). It is the old
     * behaviour, kept only where there is nothing better to answer with.
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
     * A backup of a database on its own — still attached, or archived after
     * the database was deleted. `subject_engine` is written for every one of
     * them and for nothing else, so it survives the database going.
     */
    /**
     * Does the archive hold a database dump?
     *
     * Not the same as the type. A site with no database still gets a `full`
     * backup when that is what its target says, and the dump step records the
     * truth in the manifest (`databases: []`) — which nothing read, so a full
     * restore of it got as far as "Restoring the database" and failed, after
     * taking a safety backup and putting the files back (2026-10-18). A row
     * from before the manifest recorded it answers by type, as before.
     */
    public function holdsDatabase(): bool
    {
        if (! in_array($this->type->value, ['database', 'full'], true)) {
            return false;
        }

        $manifest = (array) ($this->manifest ?? []);

        return ! array_key_exists('databases', $manifest) || $manifest['databases'] !== [];
    }

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
        // Both owners gone only ever happens by archiving — a database's on
        // its delete (2026-10-16), a site's on its delete (the follow-up).
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
     * A name that means something in a downloads folder.
     *
     * The object key is a uuid — fine on the destination, useless once four
     * of them are sitting side by side on someone's laptop. Here rather than
     * in the controller so the link and the prepared copy name a file the
     * same way.
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

        // Dots become hyphens before slugging — Str::slug strips them, which
        // turns shop.example.com into "shopexamplecom" and makes the one
        // part of the name the user recognises unreadable.
        return Str::slug(str_replace('.', '-', $site)).'-'.$stamp.'-'.$this->type->value.'.tar.gz';
    }
}
