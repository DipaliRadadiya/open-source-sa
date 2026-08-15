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
            // The one private key the panel holds that it did not generate on
            // the box. Encrypted at rest so a database copy is not a set of
            // usable keys.
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
     * Whether the web server should be given TLS directives at all. A pending
     * or failed certificate has no files behind it, and pointing a server block
     * at a path that is not there fails the config test and takes the site down
     * with it.
     */
    public function servable(): bool
    {
        return $this->status === CertificateStatus::Active
            && $this->certificate_path !== null
            && $this->private_key_path !== null;
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Days left, negative once expired.
     *
     * Worth surfacing because certificate lifetimes are shrinking: Let's
     * Encrypt has begun issuing shorter-lived certificates, and a renewal that
     * silently stopped working now has far less slack before the site breaks.
     */
    public function daysRemaining(): ?int
    {
        return $this->expires_at?->diffInDays(now(), false) * -1;
    }

    /**
     * The localized sentence for a failure, in the *viewer's* locale — the same
     * rule the activity log and the runtime installer follow, which is why the
     * classified code is stored rather than a finished string.
     */
    public function message(): ?string
    {
        if ($this->status !== CertificateStatus::Failed) {
            return null;
        }

        $key = 'certificate.failed.'.($this->reason ?: 'unknown');

        // An unrecognised code falls back rather than rendering the key at the
        // user: a missing translation must not become UI text.
        return __($key) === $key ? __('certificate.failed.unknown') : __($key);
    }

    /**
     * Names that are on the site but not on the certificate. Its own question
     * because it is the one thing that silently breaks after everything looked
     * fine: a domain added later is served by a certificate that does not
     * mention it, and the browser refuses it.
     *
     * @return array<int, string>
     */
    /**
     * The web server is presenting an older certificate than the one on disk.
     *
     * Which means a renewal landed and never reached the running process: the
     * files are current, the panel's countdown is healthy, and every visitor is
     * being handed something that expires sooner — eventually something expired.
     * It is the one certificate failure with no symptom anywhere else in the
     * panel, because everything else reads the file.
     *
     * Null when the served certificate has not been read, or could not be:
     * nothing listening, TLS refused. Not knowing is not the same as agreeing,
     * and a screen showing a reassuring tick for a check that never ran is the
     * habit this whole field exists to break.
     */
    public function servingStale(): ?bool
    {
        if ($this->served_expires_at === null || $this->expires_at === null) {
            return null;
        }

        // A minute of slack: the two dates come from the same certificate when
        // things are healthy, and a strict comparison would flag clock skew
        // between reading the file and completing a handshake.
        return $this->served_expires_at->lt($this->expires_at->subMinute());
    }

    public function missingDomains(): array
    {
        $covered = $this->domains ?? [];

        return array_values(array_diff(
            $this->application->certifiableDomains(),
            $covered,
        ));
    }
}
