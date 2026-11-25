<?php

namespace App\Http\Resources;

use App\Enums\CertificateStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_title' => $this->type->label(),
            'status' => $this->status->value,

            // The names the certificate covers, which diverge from the site's names once a domain is
            // added; the browser reports that, the server logs nothing.
            'domains' => $this->domains ?? [],
            'missing_domains' => $this->missingDomains(),

            // Names on the certificate the site no longer has: certbot fails the whole renewal if any
            // one name cannot be validated.
            'stale_domains' => $this->staleDomains(),

            'force_https' => $this->force_https,

            // Nothing renews an uploaded or self-signed certificate; don't show a renewal date.
            'auto_renew' => $this->auto_renew,
            'renewable' => $this->type->renewable(),

            'issued_at' => $this->issued_at?->format('d-m-Y H:i:s'),
            'issued_at_human' => $this->issued_at?->diffForHumans(),
            'expires_at' => $this->expires_at?->format('d-m-Y H:i:s'),
            // In days, never Carbon's rounded months: the warning threshold is in days.
            'expires_at_human' => $this->expiresInWords(),
            // What the web server presents, as opposed to what is on disk. When they differ the
            // file renewed but the running server never picked it up.
            'served_expires_at' => $this->served_expires_at?->format('d-m-Y H:i:s'),
            'served_checked_at' => $this->served_checked_at?->format('d-m-Y H:i:s'),
            // True = serving something older than the file. Null = could not be checked, which
            // is not agreement and must not render as a tick.
            'serving_stale' => $this->servingStale(),
            'days_remaining' => $this->daysRemaining(),
            'expired' => $this->expired(),

            // Its own flag so the threshold is decided in one place, not by the frontend.
            'expiring_soon' => $this->daysRemaining() !== null
                && ! $this->expired()
                && $this->daysRemaining() <= (int) config('server.certificates.expiry_warning_days'),

            // A classified code plus its sentence in the viewer's locale. Never certbot's own
            // output: it carries paths, order URLs and sometimes the account key location.
            'reason' => $this->when($this->status === CertificateStatus::Failed, $this->reason),
            'message' => $this->message(),
            'reference' => $this->when($this->status === CertificateStatus::Failed, $this->reference),
        ];
    }

    /**
     * The expiry in days, the same number as `days_remaining`.
     *
     * Carbon's single-unit sentence says "1 month" for 45 to 60 days, and its floor can read 44
     * for 45; built from the days so the two fields cannot disagree.
     */
    private function expiresInWords(): ?string
    {
        $days = $this->resource->daysRemaining();

        if ($days === null) {
            return null;
        }

        // A minute past the whole day, so the floor lands on it.
        $at = $days >= 0 ? now()->addDays($days)->addMinute() : now()->addDays($days)->subMinute();

        return $at->diffForHumans(['parts' => 1, 'minimumUnit' => 'day', 'skip' => ['y', 'month', 'week']]);
    }
}
