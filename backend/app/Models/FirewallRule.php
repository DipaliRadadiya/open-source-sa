<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['port_from', 'port_to', 'protocol', 'action', 'source_ip', 'description', 'origin', 'enabled'])]
class FirewallRule extends Model
{
    /**
     * The port range a rule may name, stated once so create and update agree.
     *
     * The floor is 1 because ufw rejects port 0 ("Bad port"); refusing it here tells the user
     * instead of failing later against ufw.
     */
    public const PORT_MIN = 1;

    public const PORT_MAX = 65535;

    /** Written by the administrator. Theirs to change at any time. */
    public const ORIGIN_USER = 'user';

    /** Seeded for the panel's own reach (SSH, HTTP, HTTPS). Locked while enforcing. */
    public const ORIGIN_DEFAULT = 'default';

    /**
     * Opened for a remote database user. The panel closes it when the last
     * user needing it goes, and the administrator may remove it any time —
     * it can cut off a database client, never the server.
     */
    public const ORIGIN_DATABASE = 'db_user';

    /**
     * Mirrors the `enabled` column default so a freshly created rule matches its row.
     *
     * Eloquent's model from `create()` never learns a database default, so `enabled` would be
     * null, cast to false, and serialise as off while ufw already has the port open.
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
     * The whole rule in a sentence — "Deny 80/tcp from 1.2.3.4".
     *
     * Shown on the rule row and in the activity log, where it goes into the existing `:ports`
     * value so older entries, which only have the ports, still read correctly.
     */
    public function summary(): string
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

    /**
     * Whether this rule is system-seeded (protected from casual deletion).
     */
    public function isProtected(): bool
    {
        return self::originIsProtected($this->origin);
    }

    public static function originIsProtected(?string $origin): bool
    {
        return ! in_array($origin, [self::ORIGIN_USER, self::ORIGIN_DATABASE], true);
    }
}
