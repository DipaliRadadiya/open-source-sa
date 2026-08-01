<?php

namespace App\Models;

use App\Enums\DomainType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id', 'domain', 'type', 'redirect_to', 'redirect_status',
    'is_test', 'dns_verified_at', 'dns_resolved_ip', 'behind_proxy',
])]
class ApplicationDomain extends Model
{
    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'is_test' => 'boolean',
            'behind_proxy' => 'boolean',
            'dns_verified_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Whether this name is ready to be put on a certificate.
     *
     * A test domain never is: nip.io is not on the Public Suffix List, so every
     * Let's Encrypt certificate issued for it anywhere in the world counts
     * against one shared weekly limit. `install.sh` already carries the same
     * warning for the panel's own hostname.
     */
    public function certifiable(): bool
    {
        return ! $this->is_test
            && $this->type !== DomainType::Redirect
            && $this->dns_verified_at !== null;
    }

    /**
     * Names the web server should answer to for this row. A redirect gets its
     * own server block, so it is not part of the main one.
     */
    public function servesContent(): bool
    {
        return $this->type !== DomainType::Redirect;
    }
}
