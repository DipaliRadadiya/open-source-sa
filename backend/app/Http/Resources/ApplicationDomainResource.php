<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationDomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'type' => $this->type->value,
            'type_title' => $this->type->label(),
            'redirect_to' => $this->redirect_to,
            'redirect_status' => $this->redirect_status,
            'is_test' => $this->is_test,

            // DNS state, and the reason a certificate button is or is not
            // offered. `dns_verified_at` null means "not pointing here" — the
            // gate exists because Let's Encrypt allows five authorisation
            // failures per hostname per hour, so guessing is expensive.
            'dns_verified' => $this->dns_verified_at !== null,
            'dns_verified_at' => $this->dns_verified_at?->format('d-m-Y H:i:s'),
            'dns_verified_at_human' => $this->dns_verified_at?->diffForHumans(),
            'dns_resolved_ip' => $this->dns_resolved_ip,

            // Cloudflare's proxy answers on its own addresses, so HTTP
            // validation never reaches this server. Surfaced as its own flag
            // because "DNS is fine but SSL fails" is the most common support
            // question this feature will generate, and the user needs to be
            // told the cause rather than left to find it.
            'behind_proxy' => $this->behind_proxy,

            // Whether this name can go on a certificate at all. A test domain
            // never can: nip.io is not on the Public Suffix List, so every
            // certificate issued for it anywhere shares one weekly limit.
            'certifiable' => $this->certifiable(),

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
