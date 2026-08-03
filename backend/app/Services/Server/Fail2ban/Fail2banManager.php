<?php

namespace App\Services\Server\Fail2ban;

use App\Exceptions\Server\Fail2ban\Fail2banException;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Support\SshPort;

/**
 * fail2ban: watches logs for repeated failures and bans the source IP.
 *
 * No DB. Live state (which jails run, who is banned) comes from
 * `fail2ban-client`, and the settings we manage live in a drop-in under
 * `jail.d/` — never in `jail.local`, which a server migrated from another
 * panel is likely to already own. Ours is one file among several; the
 * effective configuration is whatever fail2ban makes of all of them, which is
 * why live state is read back rather than assumed.
 *
 * The recurring hazard is that this feature's entire job is locking people
 * out, and it cannot tell an attacker from an administrator having a bad
 * morning. Hence the ignore list, and the refusal to enable the SSH jail
 * without an acknowledgement.
 */
class Fail2banManager
{
    /** Always ignored: banning the machine from itself helps nobody. */
    public const ALWAYS_IGNORED = ['127.0.0.1/8', '::1'];

    public function __construct(private ServerOps $serverOps) {}

    public function installed(): bool
    {
        return $this->serverOps->run(
            ['which', 'fail2ban-client'],
            ['feature' => 'fail2ban', 'op' => 'detect'],
        )->ok;
    }

    public function running(): bool
    {
        return $this->installed() && $this->client(['ping'])->ok;
    }

    public function version(): ?string
    {
        if (! $this->installed()) {
            return null;
        }

        $output = trim($this->client(['--version'])->output());

        return preg_match('/(\d+\.\d+\.\d+)/', $output, $m) === 1 ? $m[1] : null;
    }

    /**
     * The jails the panel manages, with their live state.
     *
     * `sshd` protects the way into the server and is the reason to run
     * fail2ban at all. `recidive` watches fail2ban's own log and bans anyone
     * who keeps coming back for much longer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function jails(): array
    {
        $active = $this->activeJails();

        return array_map(function (array $jail) use ($active) {
            $enabled = in_array($jail['name'], $active, true);

            return [
                ...$jail,
                'enabled' => $enabled,
                'banned' => $enabled ? $this->bannedIn($jail['name']) : [],
                'stats' => $enabled ? $this->stats($jail['name']) : null,
            ];
        }, (array) config('server.fail2ban.jails', []));
    }

    /**
     * A jail's counters.
     *
     * `currently_failed` is the one worth showing prominently: it means an
     * attack is in progress right now, as opposed to the totals, which only
     * say one happened at some point.
     *
     * @return array<string, int>
     */
    public function stats(string $jail): array
    {
        $output = $this->client(['status', $jail])->output();

        $number = function (string $label) use ($output): int {
            return preg_match('/'.preg_quote($label, '/').':\s*(\d+)/i', $output, $m) === 1 ? (int) $m[1] : 0;
        };

        return [
            'currently_failed' => $number('Currently failed'),
            'total_failed' => $number('Total failed'),
            'currently_banned' => $number('Currently banned'),
            'total_banned' => $number('Total banned'),
        ];
    }

    /**
     * Every currently banned IP, with the jail that banned it.
     *
     * @return array<int, array{ip: string, jail: string}>
     */
    public function banned(): array
    {
        $banned = [];

        foreach ($this->activeJails() as $jail) {
            $times = $this->banTimes($jail);

            foreach ($this->bannedIn($jail) as $ip) {
                $banned[] = [
                    'ip' => $ip,
                    'jail' => $jail,
                    // A list of bare addresses doesn't tell you whether to
                    // wait or to unban. Null when this fail2ban is too old to
                    // report timings — better an absent field than a guess.
                    ...($times[$ip] ?? ['banned_at' => null, 'expires_at' => null, 'seconds_left' => null]),
                ];
            }
        }

        return $banned;
    }

    /**
     * Ban and expiry times per IP for a jail.
     *
     * `--with-time` is not in every fail2ban, and an older one answers with
     * plain addresses instead of failing — so a parse that finds no timestamps
     * yields no timings rather than nonsense.
     *
     * @return array<string, array{banned_at: string, expires_at: string, seconds_left: int}>
     */
    private function banTimes(string $jail): array
    {
        $output = $this->client(['get', $jail, 'banip', '--with-time'])->output();
        $now = time();
        $times = [];

        // 1.2.3.4 	2026-07-29 11:00:00 + 3600 = 2026-07-29 12:00:00
        preg_match_all(
            '/^\s*(\S+)\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s*\+\s*(-?\d+)\s*=\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/m',
            $output,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $expires = strtotime($match[4]) ?: 0;

            $times[$match[1]] = [
                'banned_at' => $match[2],
                // A negative bantime is fail2ban's permanent ban.
                'expires_at' => (int) $match[3] < 0 ? null : $match[4],
                'seconds_left' => (int) $match[3] < 0 ? null : max(0, $expires - $now),
            ];
        }

        return $times;
    }

    /**
     * Release every banned address.
     *
     * For the case this exists to serve — an office or a VPN range banned by
     * mistake — unbanning eleven addresses one at a time is not the moment for
     * careful clicking.
     *
     * @return array<int, string> the addresses released
     */
    public function unbanAll(): array
    {
        $released = [];

        foreach ($this->activeJails() as $jail) {
            foreach ($this->bannedIn($jail) as $ip) {
                if ($this->client(['set', $jail, 'unbanip', $ip])->ok) {
                    $released[] = $ip;
                }
            }
        }

        return array_values(array_unique($released));
    }

    /**
     * Settings from our drop-in — what the settings form controls.
     *
     * Deliberately not read back from fail2ban: the effective value can come
     * from any of several files, and showing another file's value in a form
     * that writes ours would invite the user to "fix" a number that then
     * refuses to change.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $config = $this->readDropIn();

        return [
            'bantime' => (int) ($config['bantime'] ?? config('server.fail2ban.defaults.bantime')),
            'findtime' => (int) ($config['findtime'] ?? config('server.fail2ban.defaults.findtime')),
            'maxretry' => (int) ($config['maxretry'] ?? config('server.fail2ban.defaults.maxretry')),
            'ignore_ips' => $this->ignoreIps(),
        ];
    }

    /**
     * The ignore list, without the loopback entries we always add — the user
     * did not put those there and cannot remove them.
     *
     * @return array<int, string>
     */
    public function ignoreIps(): array
    {
        $raw = (string) ($this->readDropIn()['ignoreip'] ?? '');

        return array_values(array_diff(
            array_filter(preg_split('/\s+/', trim($raw)) ?: []),
            self::ALWAYS_IGNORED,
        ));
    }

    /**
     * Rewrite the drop-in and reload.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $ignoreIps
     * @param  array<string, bool>  $enabled  jail name => enabled
     */
    public function write(array $settings, array $ignoreIps, array $enabled): void
    {
        $ignore = implode(' ', array_unique([...self::ALWAYS_IGNORED, ...$ignoreIps]));

        $body = "# Managed by the control panel — do not edit by hand\n"
            ."[DEFAULT]\n"
            ."bantime = {$settings['bantime']}\n"
            ."findtime = {$settings['findtime']}\n"
            ."maxretry = {$settings['maxretry']}\n"
            ."ignoreip = {$ignore}\n"
            // systemd's journal is the reliable source on a modern Ubuntu;
            // /var/log/auth.log is not guaranteed to exist or be written to.
            ."backend = systemd\n";

        foreach ((array) config('server.fail2ban.jails', []) as $jail) {
            $body .= "\n[{$jail['name']}]\n"
                .'enabled = '.(($enabled[$jail['name']] ?? false) ? 'true' : 'false')."\n";

            foreach ($this->jailOptions($jail) as $key => $value) {
                $body .= "{$key} = {$value}\n";
            }
        }

        $result = $this->serverOps->run(
            ['tee', $this->dropInPath()],
            ['feature' => 'fail2ban', 'op' => 'write_config'],
            input: $body,
        );

        if ($result->failed()) {
            throw Fail2banException::operationFailed($result->reference);
        }

        $this->reload();
    }

    /**
     * A jail's options, with `{ssh_port}` resolved.
     *
     * The sshd jail defaults to `port = ssh`, which resolves to 22 — and this
     * panel lets the user move SSH somewhere else. Left alone, fail2ban would
     * detect the attack correctly (it reads the journal, not the socket) and
     * then ban a port nobody is using, while the screen reported the server as
     * protected. The port has to come from the same setting that moved it.
     *
     * @param  array<string, mixed>  $jail
     * @return array<string, string>
     */
    private function jailOptions(array $jail): array
    {
        $port = (string) $this->sshPort();

        return array_map(
            fn ($value) => str_replace('{ssh_port}', $port, (string) $value),
            (array) ($jail['options'] ?? []),
        );
    }

    /**
     * Shared with the firewall, which must never close this port, and with
     * Settings, which changes it. One source, so they cannot disagree.
     */
    public function sshPort(): int
    {
        return SshPort::current();
    }

    /**
     * Reload rather than restart: a restart forgets every active ban, quietly
     * undoing the protection the user just came here to configure.
     */
    public function reload(): void
    {
        $result = $this->client(['reload']);

        if ($result->failed()) {
            throw Fail2banException::operationFailed($result->reference);
        }
    }

    public function ban(string $ip, string $jail): void
    {
        $this->assertJailActive($jail);

        $result = $this->client(['set', $jail, 'banip', $ip]);

        if ($result->failed()) {
            throw Fail2banException::operationFailed($result->reference);
        }
    }

    /**
     * Unban an IP. Without a jail, from every jail holding it — a user
     * clicking "unban" on an address means it should be able to connect
     * again, not that it should stay banned by the jail they didn't look at.
     *
     * @return array<int, string> the jails it was released from
     */
    public function unban(string $ip, ?string $jail = null): array
    {
        $jails = $jail !== null ? [$jail] : $this->activeJails();
        $released = [];

        foreach ($jails as $name) {
            if (in_array($ip, $this->bannedIn($name), true) && $this->client(['set', $name, 'unbanip', $ip])->ok) {
                $released[] = $name;
            }
        }

        if ($released === []) {
            throw Fail2banException::notBanned();
        }

        return $released;
    }

    /**
     * @return array<int, string>
     */
    public function activeJails(): array
    {
        if (! $this->running()) {
            return [];
        }

        $output = $this->client(['status'])->output();

        if (preg_match('/Jail list:\s*(.*)$/m', $output, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }

    /**
     * @return array<int, string>
     */
    public function bannedIn(string $jail): array
    {
        $output = $this->client(['get', $jail, 'banned'])->output();

        // Returned as a Python list literal: ['1.2.3.4', '5.6.7.8']
        preg_match_all("/'([^']+)'/", $output, $matches);

        return $matches[1] ?? [];
    }

    private function assertJailActive(string $jail): void
    {
        if (! in_array($jail, $this->activeJails(), true)) {
            throw Fail2banException::jailNotActive($jail);
        }
    }

    /**
     * Our drop-in's `key = value` pairs, or an empty array when it doesn't
     * exist yet.
     *
     * @return array<string, string>
     */
    private function readDropIn(): array
    {
        $path = $this->dropInPath();

        if (! is_readable($path)) {
            return [];
        }

        $values = [];
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) ?: [] as $line) {
            // Only the [DEFAULT] block holds what the settings form edits.
            if (preg_match('/^\s*\[/', $line) === 1 && ! str_contains($line, 'DEFAULT')) {
                break;
            }
            if (preg_match('/^\s*([a-z_.]+)\s*=\s*(.*)$/i', $line, $m) === 1) {
                $values[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $values;
    }

    public function dropInPath(): string
    {
        return rtrim((string) config('server.fail2ban.jail_d'), '/')
            .'/'.(string) config('server.fail2ban.drop_in', 'panel.local');
    }

    /**
     * @param  array<int, string>  $args
     */
    private function client(array $args): ServerOpsResult
    {
        return $this->serverOps->run(
            [(string) config('server.fail2ban.client', 'fail2ban-client'), ...$args],
            ['feature' => 'fail2ban', 'op' => $args[0] ?? 'client'],
        );
    }
}
