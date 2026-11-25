<?php

namespace App\Models;

use App\Enums\DomainType;
use App\Services\Server\Applications\DnsVerifier;
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

    /**
     * What a redirect target may contain: a scheme, a host and a path.
     *
     * The target is written verbatim into the vhost, so characters with meaning there are
     * rejected: `;` and `#` (nginx directive injection), `$` (nginx variable / Apache
     * back-reference), and `&`, `'`, `"` (HTML-escaped by Blade). No query string or fragment:
     * every template appends the request path.
     */
    public const REDIRECT_TARGET_PATTERN = '/^https?:\/\/[^\s;#$&?"\'`\\\\{}<>]+$/i';

    /**
     * The target as the vhost uses it: without a trailing slash, because every template
     * appends the request path (which starts with `/`).
     */
    public function redirectTarget(): ?string
    {
        $target = rtrim((string) $this->redirect_to, '/');

        return $target === '' ? null : $target;
    }

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
     * Resolve `{domain}` in a route from either the id or the hostname.
     *
     * `application_domains.domain` is unique, so a hostname identifies one row. Ownership is
     * still checked by the controller: resolving a name is not authorising access to it.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $value = trim((string) $value);

        // Numeric goes to the id, so an all-digit hostname cannot shadow a row id, and a hostname
        // never reaches an `id = '...'` comparison (a type error on some drivers).
        return is_numeric($value)
            ? parent::resolveRouteBinding($value)
            : static::query()->where('domain', mb_strtolower($value))->first();
    }

    /**
     * Whether a hostname is one of the wildcard-DNS services that resolve any
     * name to an address encoded in it.
     *
     * Flags a domain as temporary even when the caller said otherwise: a mislabelled `nip.io`
     * name would spend a certificate rate limit shared with the whole internet.
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

    /**
     * Whether a certificate can be requested for this name.
     *
     * Only DNS decides: does the name resolve to this server (the ACME challenge needs it).
     * {@see DnsVerifier} marks wildcard-DNS hostnames verified by construction; `is_test` does
     * not matter here.
     *
     * A redirect is certifiable too: browsers with HSTS for the old name refuse the plaintext
     * hop, so it must answer on HTTPS to redirect anyone.
     */
    public function certifiable(): bool
    {
        return $this->dns_verified_at !== null;
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
