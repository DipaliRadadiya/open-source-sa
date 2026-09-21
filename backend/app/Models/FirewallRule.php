<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['port_from', 'port_to', 'protocol', 'action', 'source_ip', 'description', 'origin', 'enabled'])]
class FirewallRule extends Model
{
    /**
     * Mirrors the `enabled` column default so a freshly created rule says the
     * same thing as the row behind it.
     *
     * A database default applies in the database. The model Eloquent hands
     * back from `create()` never learns it, so `enabled` is null there, casts
     * to false, and the created rule serialises as **off while ufw already has
     * the port open** — the panel reporting a closed port that is open, which
     * is the 2026-09-08 divergence in miniature.
     *
     * Stated here rather than at each call site because that was already tried:
     * `RecordDefaultRules` spells `enabled` out for exactly this reason, and
     * `CreateFirewallRule` — written later — did not, because nothing made it.
     * `WorkerController` solved the same trap a third way, with `refresh()`.
     * Three workarounds for one missing default; a default the model owns
     * cannot be forgotten by the next caller.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
    ];

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
