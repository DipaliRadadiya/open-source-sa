<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['port_from', 'port_to', 'protocol', 'action', 'source_ip', 'description', 'origin', 'enabled'])]
class FirewallRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port_from' => 'integer',
            'port_to' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The port token for a ufw spec: `443` or `50000:50100`.
     */
    public function portSpec(): string
    {
        return $this->port_to ? "{$this->port_from}:{$this->port_to}" : (string) $this->port_from;
    }

    /**
     * Whether this rule is system-seeded (protected from casual deletion).
     */
    public function isProtected(): bool
    {
        return $this->origin !== 'user';
    }
}
