<?php

namespace App\Services\Server\Fail2ban;

use App\Exceptions\Server\Fail2ban\Fail2banException;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

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

        return array_map(fn (array $jail) => [
            ...$jail,
            'enabled' => in_array($jail['name'], $active, true),
            'banned' => $this->bannedIn($jail['name']),
        ], (array) config('server.fail2ban.jails', []));
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
            foreach ($this->bannedIn($jail) as $ip) {
                $banned[] = ['ip' => $ip, 'jail' => $jail];
            }
        }

        return $banned;
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

        $body = "# Managed by ServerAvatar OSS — do not edit by hand\n"
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

            foreach ((array) ($jail['options'] ?? []) as $key => $value) {
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
