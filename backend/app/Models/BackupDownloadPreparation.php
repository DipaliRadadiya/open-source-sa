<?php

namespace App\Models;

use App\Jobs\FetchBackupForDownload;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backup on SFTP or FTP, copied to the panel so the browser can download it.
 *
 * `preparing` while the queue fetches it, `ready` (with `expires_at`) once the
 * copy is on disk, `failed` with a `reason` otherwise. See
 * {@see FetchBackupForDownload}.
 */
#[Fillable(['backup_id', 'user_id', 'reference', 'status', 'path', 'size_bytes', 'reason', 'ready_at', 'expires_at'])]
class BackupDownloadPreparation extends Model
{
    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Still being fetched, and young enough that its job can be alive.
     *
     * A worker killed outright never reaches `failed()`, and a row stranded at
     * `preparing` would be handed back to every later click forever. Past the
     * job's own timeout nothing can still be writing the copy.
     */
    public function isPreparing(): bool
    {
        return $this->status === self::PREPARING
            && $this->created_at !== null
            && $this->created_at->gt(now()->subSeconds(self::staleAfterSeconds()));
    }

    /** Ready, in date, and the copy still on disk. */
    public function isReady(): bool
    {
        return $this->status === self::READY
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && is_string($this->path)
            && is_file($this->path);
    }

    /** Neither of the above: the next click starts a fresh copy. */
    public function isSpent(): bool
    {
        return ! $this->isPreparing() && ! $this->isReady();
    }

    /** @param  Builder<BackupDownloadPreparation>  $query */
    public function scopeStale(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('status', self::READY)->where('expires_at', '<=', now()))
            ->orWhere(fn (Builder $q) => $q->where('status', self::FAILED)->where('updated_at', '<=', now()->subSeconds(self::ttlSeconds())))
            ->orWhere(fn (Builder $q) => $q->where('status', self::PREPARING)->where('created_at', '<=', now()->subSeconds(self::staleAfterSeconds()))));
    }

    public static function ttlSeconds(): int
    {
        return max(60, (int) config('server.backups.download_ttl_seconds', 3600));
    }

    public static function staleAfterSeconds(): int
    {
        return FetchBackupForDownload::timeoutSeconds() + 300;
    }
}
