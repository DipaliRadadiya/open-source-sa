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

            // The names this certificate covers, which is not the same as the
            // names the site answers to. They diverge the moment a domain is
            // added, and that divergence is the failure the panel exists to
            // catch — the browser reports it, the server logs nothing.
            'domains' => $this->domains ?? [],
            'missing_domains' => $this->missingDomains(),

            'force_https' => $this->force_https,

            // Nothing can renew an uploaded or self-signed certificate. Say so
            // rather than showing a renewal date that will never happen.
            'auto_renew' => $this->auto_renew,
            'renewable' => $this->type->renewable(),

            'issued_at' => $this->issued_at?->format('d-m-Y H:i:s'),
            'issued_at_human' => $this->issued_at?->diffForHumans(),
            'expires_at' => $this->expires_at?->format('d-m-Y H:i:s'),
            'expires_at_human' => $this->expires_at?->diffForHumans(),
            // What the web server is actually presenting, as opposed to what
            // is on disk. They agree on a healthy site; when they do not, the
            // file renewed and the running server never picked it up, so the
            // countdown above is reassuring and every visitor gets a warning.
            'served_expires_at' => $this->served_expires_at?->format('d-m-Y H:i:s'),
            'served_checked_at' => $this->served_checked_at?->format('d-m-Y H:i:s'),
            // True = serving something older than the file. Null = nobody has
            // managed to look, which is not the same as agreement and must not
            // render as a tick.
            'serving_stale' => $this->servingStale(),
            'days_remaining' => $this->daysRemaining(),
            'expired' => $this->expired(),

            // Its own flag rather than left to the frontend to compute from
            // days_remaining, so the threshold is one decision made in one
            // place — and so it can move when certificate lifetimes shrink.
            'expiring_soon' => $this->daysRemaining() !== null
                && ! $this->expired()
                && $this->daysRemaining() <= (int) config('server.certificates.expiry_warning_days'),

            // A classified code plus the sentence for it in the *viewer's*
            // locale. Never certbot's own output: it carries paths, order URLs
            // and occasionally the account key location, and it is unreadable.
            'reason' => $this->when($this->status === CertificateStatus::Failed, $this->reason),
            'message' => $this->message(),
            'reference' => $this->when($this->status === CertificateStatus::Failed, $this->reference),
        ];
    }
}
