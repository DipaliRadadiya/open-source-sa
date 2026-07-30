<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FirewallRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'port_from' => $this->port_from,
            'port_to' => $this->port_to,
            'protocol' => $this->protocol,
            'action' => $this->action,
            'source_ip' => $this->source_ip,
            'description' => $this->description,
            'origin' => $this->origin,
            // Off means kept but not applied — the rule is still here.
            'enabled' => (bool) $this->enabled,
            // Non-`user` rules are system-seeded and protected from deletion.
            'protected' => $this->isProtected(),
            // Localized plain-English row, e.g. "Allow 443/tcp from Anywhere".
            'summary' => $this->summary(),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }

    private function summary(): string
    {
        $ports = $this->portSpec();
        if ($this->protocol !== 'all') {
            $ports .= '/'.$this->protocol;
        }

        return __('firewall.summary', [
            'action' => __('firewall.actions.'.$this->action),
            'ports' => $ports,
            'source' => $this->source_ip ?: __('firewall.anywhere'),
        ]);
    }
}
