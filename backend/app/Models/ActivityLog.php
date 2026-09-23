<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['user_id', 'type', 'action', 'subject_type', 'subject_id', 'properties'])]
class ActivityLog extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Free-text search over what a row is and what it was about.
     *
     * `type` and `action` alone could not find a site, a PHP version or an IP
     * — searching "f2b-test" or "8.2" returned nothing, though every one of
     * those rows carries the value in `properties`. The JSON text is matched
     * as stored: JSON escapes `/` and non-ASCII, so a search containing those
     * will not match, and a property's *name* matches too ("version" finds
     * every row that has one). Properties hold names and labels only — never
     * secrets — which is what makes them safe to search.
     */
    public static function search(Builder $query, string $search): Builder
    {
        $like = '%'.trim($search).'%';

        // Compared as text, and case-insensitively like the other columns.
        // PostgreSQL has no ILIKE on json. MySQL and MariaDB store JSON under
        // a binary collation, so a plain LIKE is case-sensitive there —
        // measured on MariaDB 10.11: `properties LIKE '%f2b%'` misses
        // "F2B-Test", `CAST(properties AS CHAR)` finds it.
        $properties = match ($query->getConnection()->getDriverName()) {
            'pgsql' => DB::raw('CAST(properties AS TEXT)'),
            'mysql', 'mariadb' => DB::raw('CAST(properties AS CHAR)'),
            default => 'properties',
        };

        return $query->where(fn (Builder $query) => $query
            ->whereLike('type', $like)
            ->orWhereLike('action', $like)
            ->orWhereLike($properties, $like));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
