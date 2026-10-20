<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A panel-tracked database on one engine. Owns its users (one-to-many) and
 * may belong to an application. `driver()` collapses the 3 engines to the 2
 * driver families (sql|mongo).
 */
#[Fillable(['name', 'engine', 'charset', 'collation', 'application_id', 'size_bytes'])]
class Database extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function driver(): string
    {
        return (string) config("server.databases.engines.{$this->engine}.driver", 'sql');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    /**
     * Its own backup schedule — only for a database with no site. A site's
     * database is backed up by the site's target (2026-10-16 decision).
     */
    public function backupTarget(): HasOne
    {
        return $this->hasOne(BackupTarget::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /** The newest backup of this database on its own (RC-23). */
    public function latestBackup(): HasOne
    {
        return $this->hasOne(Backup::class)->latestOfMany();
    }
}
