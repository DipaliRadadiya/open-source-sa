<?php

namespace App\Models;

use App\Services\Addons\SiteRedisAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site's own Redis ACL user, for Object Cache Pro. {@see SiteRedisAccount}
 */
class ApplicationRedisAccount extends Model
{
    protected $fillable = ['application_id', 'username', 'password', 'prefix', 'settings'];

    protected $hidden = ['password', 'settings'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            // The Object Cache Pro token and options Central last sent, kept
            // to rewrite the site's settings when the password rotates.
            'settings' => 'encrypted:array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
