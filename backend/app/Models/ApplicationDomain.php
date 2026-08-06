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
     *
     * A redirect *is* certifiable, which is not obvious. `http://old` →
     * `https://new` looks like it needs no certificate of its own, but any
     * browser that has seen HSTS for `old` refuses the plaintext hop and never
     * reaches the redirect at all. The old name has to answer on HTTPS to be
     * able to send anyone anywhere.
     */
    /**
     * Whether a hostname is one of the wildcard-DNS services that resolve any
     * name to an address encoded in it.
     *
     * Used to flag a domain as temporary even when the caller said otherwise.
     * The client tells us which kind it is, but a `nip.io` name mislabelled as
     * the user's own would go on a certificate request and spend from a weekly
     * limit shared with the entire internet — a claim worth checking rather
     * than taking on trust.
     */
    public static function looksTemporary(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        foreach ((array) config('server.temporary_domain_suffixes', ['nip.io', 'sslip.io']) as $suffix) {
            if (str_ends_with($domain, '.'.strtolower((string) $suffix))) {
                return true;
            }
        }

        return false;
    }

    public function certifiable(): bool
    {
        return ! $this->is_test && $this->dns_verified_at !== null;
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
