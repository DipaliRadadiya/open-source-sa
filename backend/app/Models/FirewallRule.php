<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['port_from', 'port_to', 'protocol', 'action', 'source_ip', 'description', 'origin', 'enabled'])]
class FirewallRule extends Model
{
    /**
     * The port range a rule may name, stated once so create and update cannot
     * disagree about it.
     *
     * They did. Creating a rule capped both ends at **65534**, while editing
     * one allowed `port_to` up to 65535 — so "allow everything from 9000 up",
     * the natural way to write an open upper range, was refused on the way in
     * and accepted on the way through the edit screen. Nothing in the codebase
     * justified 65534: every other port field in this application uses 65535,
     * and `ufw --dry-run allow 9000:65535/tcp` was measured on a real server
     * and accepted. 65535 is a port like any other.
     *
     * The floor is 1 because 0 is not a port ufw will take — measured too:
     * `ufw --dry-run allow 0/tcp` answers `ERROR: Bad port`. Refusing it here
     * means the user is told why, instead of the rule being stored and failing
     * later against ufw where nobody is watching.
     */
    public const PORT_MIN = 1;

    public const PORT_MAX = 65535;

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
