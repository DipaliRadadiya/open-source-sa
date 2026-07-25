<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal stub — the full Application feature (deploy recipes, SiteTypes,
 * databases, domains, SSL) will build on this later.
 */
#[Fillable(['system_user_id', 'name', 'domain', 'site_type', 'php_version', 'status'])]
class Application extends Model
{
    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }
}
