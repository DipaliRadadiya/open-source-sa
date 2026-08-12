<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\FirewallRule;
use App\Models\SyncRun;
use App\Services\Server\ServerOps;

/**
 * Firewall rules already on the box.
 *
 * Opt-in, and the only resource that is. Every other kind of adoption leaves
 * the server exactly as it was; this one populates the screen someone will
 * later use to *change* the firewall, and a half-imported rule list is a
 * worse starting point for that than an empty one — it looks complete.
 *
 * So it runs only when asked for, and anything it cannot represent faithfully
 * is reported as skipped rather than approximated. A rule recorded as
 * narrower or wider than the one actually in force is the failure mode here,
 * and it is not one anybody notices until the wrong thing is reachable.
 */
class FirewallRuleDiscoverer implements Discoverable
{
    public function __construct(private ServerOps $serverOps) {}

    public function resourceType(): string
    {
        return 'firewall_rule';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function discover(SyncRun $run): array
    {
        // Not an omission — the caller chose not to ask. Reporting a line
        // every run to say so would be noise on the screen that matters.
        if (! $run->includesFirewall()) {
            return [];
        }

        $result = $this->serverOps->run(
            ['ufw', 'status', 'numbered'],
            ['feature' => 'sync', 'op' => 'discover_firewall'],
            timeout: 30,
        );

        if ($result->failed()) {
            return [];
        }

        $existing = FirewallRule::query()->get();
        $found = [];

        foreach (preg_split('/\r?\n/', $result->output()) ?: [] as $line) {
            $line = trim($line);

            // `[ 3] 3306    ALLOW IN    203.0.113.5`
            if (! preg_match('/^\[\s*\d+\]\s+(.+?)\s{2,}(\w+)\s+(IN|OUT)\s{2,}(.+)$/i', $line, $matches)) {
                continue;
            }

            [, $to, $action, $direction, $from] = $matches;
            $to = trim($to);
            $from = trim($from);

            // ufw lists the v6 half of a rule as its own numbered entry. The
            // panel stores one row per rule, so adopting both would double
            // every rule on the box.
            if (str_contains($to, '(v6)') || str_contains($from, '(v6)')) {
                continue;
            }

            if (strtoupper($direction) !== 'IN') {
                // The panel's rules are inbound only. An outbound rule
                // recorded here would be applied as an inbound one.
                $found[] = $this->skip($to, $from, 'firewall_direction_unsupported');

                continue;
            }

            $normalisedAction = match (strtoupper($action)) {
                'ALLOW' => 'allow',
                'DENY' => 'deny',
                // LIMIT and REJECT behave differently from both — rate
                // limiting and an active refusal. Recording either as a plain
                // allow or deny would misstate what the server does.
                default => null,
            };

            if ($normalisedAction === null) {
                $found[] = $this->skip($to, $from, 'firewall_action_unsupported');

                continue;
            }

            $port = $this->parsePort($to);

            if ($port === null) {
                // An application profile — `OpenSSH`, `Nginx Full`. The ports
                // behind it live in /etc/ufw/applications.d and can change
                // when the package updates, so storing today's numbers would
                // be a snapshot pretending to be the rule.
                $found[] = $this->skip($to, $from, 'firewall_app_profile');

                continue;
            }

            $sourceIp = $this->parseSource($from);

            $duplicate = $existing->first(fn (FirewallRule $rule): bool => (int) $rule->port_from === $port['from']
                && (int) $rule->port_to === (int) $port['to']
                && $rule->protocol === $port['protocol']
                && $rule->action === $normalisedAction
                && $rule->source_ip === $sourceIp);

            if ($duplicate !== null) {
                continue;
            }

            $found[] = [
                'key' => sprintf(
                    '%s:%s:%s:%s',
                    $normalisedAction,
                    $port['from'].($port['to'] ? '-'.$port['to'] : ''),
                    $port['protocol'],
                    $sourceIp ?? 'any',
                ),
                'label' => trim($to.' '.strtolower($action).' from '.$from),
                // Read verbatim from ufw. Nothing here is inferred, which is
                // why anything that cannot be read verbatim is skipped above.
                'confidence' => 100,
                'evidence' => [
                    'to' => $to,
                    'from' => $from,
                    'action' => $normalisedAction,
                ],
                'attributes' => [
                    'port_from' => $port['from'],
                    'port_to' => $port['to'],
                    'protocol' => $port['protocol'],
                    'action' => $normalisedAction,
                    'source_ip' => $sourceIp,
                    'description' => 'Imported from ufw',
                ],
            ];
        }

        return $found;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        return FirewallRule::create($attributes + [
            // `user`, not `default`: these were made by hand on the server
            // and are not the panel's seeded set. The badge and the delete
            // protection both key off this.
            'origin' => 'user',
            // True, because the rule is in force. Recording it disabled would
            // describe a firewall with holes it does not have.
            'enabled' => true,
        ]);
    }

    /**
     * `80/tcp`, `8000:9000/tcp`, `3306` → ports and protocol.
     *
     * Null for anything else, which is how an application profile is told
     * apart from a port.
     *
     * @return array{from: int, to: int|null, protocol: string}|null
     */
    private function parsePort(string $to): ?array
    {
        if (! preg_match('/^(\d+)(?::(\d+))?(?:\/(tcp|udp))?$/i', trim($to), $matches)) {
            return null;
        }

        return [
            'from' => (int) $matches[1],
            'to' => isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null,
            // ufw prints no protocol when the rule covers both.
            'protocol' => strtolower($matches[3] ?? '') ?: 'all',
        ];
    }

    /** `Anywhere` means no restriction; anything else is an address or CIDR. */
    private function parseSource(string $from): ?string
    {
        $from = trim($from);

        return strcasecmp($from, 'Anywhere') === 0 ? null : $from;
    }

    /**
     * @return array<string, mixed>
     */
    private function skip(string $to, string $from, string $reason): array
    {
        return [
            'key' => trim($to.' from '.$from),
            'skip' => $reason,
            'evidence' => ['to' => $to, 'from' => $from],
        ];
    }
}
