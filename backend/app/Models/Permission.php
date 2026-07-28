<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    /**
     * Sidebar/nav label in the caller's locale (resolved by SetLocale from
     * Accept-Language). Falls back to the DB `title` (English) when a `nav`
     * translation key doesn't exist yet — so a newly-seeded permission still
     * renders a label.
     */
    public function localizedTitle(): string
    {
        $key = 'nav.'.$this->name;

        return trans()->has($key) ? __($key) : (string) $this->title;
    }

    /**
     * Sidebar section header for this permission's sub-level, in the caller's
     * locale. Same fallback contract as localizedTitle(): an unknown sub-level
     * still renders (title-cased) instead of returning an empty header.
     */
    public function localizedSubLevel(): string
    {
        $key = 'nav_group.'.$this->sub_level;

        return trans()->has($key) ? __($key) : ucfirst((string) $this->sub_level);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['view', 'manage'])
            ->withTimestamps();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
