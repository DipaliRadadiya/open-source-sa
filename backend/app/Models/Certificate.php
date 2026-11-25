<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id', 'type', 'status', 'domains',
        'certificate_path', 'private_key_path', 'chain_path', 'uploaded_private_key',
        'force_https', 'auto_renew', 'issued_at', 'expires_at', 'served_expires_at', 'served_checked_at', 'reason', 'reference',
    ];

    protected $hidden = ['uploaded_private_key'];

    protected function casts(): array
    {
        return [
            'type' => CertificateType::class,
            'status' => CertificateStatus::class,
            'domains' => 'array',
            // The only private key the panel holds that it did not generate; encrypted at rest.
            'uploaded_private_key' => 'encrypted',
            'force_https' => 'boolean',
            'auto_renew' => 'boolean',
            'issued_at' => 'datetime',
            'served_expires_at' => 'datetime',
            'served_checked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * The certbot lineage this certificate lives in, read from where its files are, since
     * older certificates keep the name they were issued under. Null if certbot does not manage it.
     */
    public function lineageName(): ?string
    {
        if ($this->type !== CertificateType::LetsEncrypt) {
            return null;
        }

        // No files recorded (an issue that never finished): the name it would
        // have had, so a removal still stops whatever certbot did create.
        $name = blank($this->certificate_path) ? '' : basename(dirname((string) $this->certificate_path));

        return $name === '' || $name === '.' ? ($this->application?->slug ?: null) : $name;
    }

    /**
     * An uploaded certificate whose chain is a file of its own (v7's `{app}_ca_bundle.crt` on
     * Apache and OpenLiteSpeed), so the vhost has to name it. Uploads made here bundle the
     * chain into the `.crt`.
     */
    public function servesSeparateChain(): bool
    {
        // Matched by name: a row may still carry a stale Let's Encrypt `chain.pem`.
        return $this->type === CertificateType::Custom
            && str_ends_with((string) $this->chain_path, '_ca_bundle.crt');
    }

    /**
     * Whether the web server should be given TLS directives at all. A pending or failed
     * certificate has no files, and pointing a server block at a missing path takes the site down.
     */
    public function servable(): bool
    {
        return $this->status === CertificateStatus::Active
            && $this->certificate_path !== null
            && $this->private_key_path !== null;
    }

    /**
     * Whether this certificate is valid for a hostname.
     *
     * A wildcard covers one label only: *.example.com covers app.example.com,
     * never example.com or deep.app.example.com.
     */
    public function covers(string $domain): bool
    {
        $domain = strtolower(rtrim($domain, '.'));

        foreach ($this->domains ?? [] as $covered) {
            $covered = strtolower(rtrim((string) $covered, '.'));

            if ($covered === $domain) {
                return true;
            }

            if (! str_starts_with($covered, '*.')) {
                continue;
            }

            $suffix = substr($covered, 2);

            if (str_ends_with($domain, '.'.$suffix)
                && substr_count($domain, '.') === substr_count($suffix, '.') + 1) {
                return true;
            }
        }

        return false;
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Days left, negative once expired.
     *
     * Null means "no expiry known" (pending, issuing or failed), which is NOT zero days.
     *
     * Rounded up, not truncated: Carbon 3 returns a float, and an int cast would report `0` for
     * a certificate with ten hours left. Only a genuinely expired one goes negative.
     */
    public function daysRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) ceil($this->expires_at->diffInDays(now(), false) * -1);
    }

    /**
     * The localized sentence for a failure, in the *viewer's* locale; hence the stored code
     * rather than a finished string.
     */
    public function message(): ?string
    {
        if ($this->status !== CertificateStatus::Failed) {
            return null;
        }

        $key = 'certificate.failed.'.($this->reason ?: 'unknown');

        // An unrecognised code falls back: a missing translation must not become UI text.
        return __($key) === $key ? __('certificate.failed.unknown') : __($key);
    }

    /**
     * Names that are on the site but not on the certificate: a domain added later is served by a
     * certificate that does not mention it, and the browser refuses it.
     *
     * @return array<int, string>
     */
    /**
     * The web server is presenting an older certificate than the one on disk.
     *
     * A renewal landed but never reached the running process. No other symptom shows in the
     * panel, because everything else reads the file.
     *
     * Null when the served certificate could not be read (nothing listening, TLS refused):
     * not knowing is not agreeing.
     */
    public function servingStale(): ?bool
    {
        if ($this->served_expires_at === null || $this->expires_at === null) {
            return null;
        }

        // A minute of slack for clock skew between reading the file and the handshake.
        return $this->served_expires_at->lt($this->expires_at->subMinute());
    }

    /**
     * The site's certifiable names this certificate does not cover.
     *
     * Asks {@see covers()} rather than comparing names: a wildcard `*.example.com` covers
     * `shop.example.com` without naming it, but not the bare `example.com` or deeper labels.
     *
     * @return array<int, string>
     */
    public function missingDomains(): array
    {
        return array_values(array_filter(
            $this->application->certifiableDomains(),
            fn (string $domain) => ! $this->covers($domain),
        ));
    }

    /**
     * Names on the certificate that the application no longer has.
     *
     * The opposite of {@see missingDomains()}, and the dangerous direction: `certbot renew`
     * re-validates every name in a lineage and fails the whole renewal if one cannot be
     * validated, so a removed alias silently stops the remaining domains from renewing.
     *
     * Reported, not corrected: fixing it means reissuing, which spends a rate limit.
     * Only Let's Encrypt renews, so only it can be broken this way.
     *
     * @return array<int, string>
     */
    public function staleDomains(): array
    {
        if ($this->type !== CertificateType::LetsEncrypt) {
            return [];
        }

        // Every name the application answers to, not just the certifiable ones.
        $current = $this->application?->domains->pluck('domain')->all() ?? [];

        return array_values(array_diff($this->domains ?? [], $current));
    }
}
