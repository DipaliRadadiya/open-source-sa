<?php

namespace App\Models;

use App\Enums\DomainType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'application_id', 'domain', 'type', 'redirect_to', 'redirect_status',
    'is_test', 'dns_verified_at', 'dns_resolved_ip', 'behind_proxy',
])]
class ApplicationDomain extends Model
{
    use HasFactory;

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
     * Resolve `{domain}` in a route from either the id or the hostname.
     *
     * The parameter is called `domain` and the model has a `domain` column, so
     * passing the hostname is the obvious reading — and it used to fail with
     * "No query results for model [App\Models\ApplicationDomain]
     * blog.example.com", a 404 that says nothing about what was actually
     * wrong. Accepting both is cheaper than a parameter that means something
     * other than its name.
     *
     * Unambiguous: `application_domains.domain` is unique across the table, so
     * a hostname identifies exactly one row. Ownership is still checked by the
     * controller — resolving a name is not authorising access to it.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $value = trim((string) $value);

        // Numeric goes to the id, so a hostname that happens to be all digits
        // cannot shadow a row id — and a non-numeric value never reaches an
        // `id = 'blog.example.com'` comparison, which some drivers treat as a
        // type error rather than simply no match.
        return is_numeric($value)
            ? parent::resolveRouteBinding($value)
            : static::query()->where('domain', mb_strtolower($value))->first();
    }

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
