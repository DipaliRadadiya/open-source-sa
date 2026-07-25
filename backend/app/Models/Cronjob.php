<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'username', 'system_user_id', 'command', 'expression', 'active'])]
class Cronjob extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * A stable, unique slug from the name — the key for the cron.d filename.
     * Suffixes `-2`, `-3`, … on collision. Migration-safe (stored, not derived
     * from the auto-increment id).
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'cronjob';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }
}
