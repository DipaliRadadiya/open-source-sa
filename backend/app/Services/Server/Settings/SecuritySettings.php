<?php

namespace App\Services\Server\Settings;

use App\Contracts\Firewall;
use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Models\FirewallRule;
use App\Models\SshKey;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\SshUsersGroup;
use Illuminate\Validation\ValidationException;

/**
 * SSH hardening — port, root login, password auth. Written to a managed
 * sshd_config.d drop-in (non-destructive), validated with `sshd -t` BEFORE
 * reload (a bad config can never take SSH down), with a firewall port-sync and
 * a lockout guard.
 */
class SecuritySettings implements SettingGroup
{
    private const ROOT_LOGIN = ['yes', 'no', 'prohibit-password'];

    // Keywords `sshd -T` prints once per entry — see parseEffective().
    private const LIST_KEYWORDS = ['allowgroups', 'allowusers', 'denygroups', 'denyusers'];

    // sshd's Include takes the FIRST value seen for each keyword — the
    // opposite of systemd-style drop-ins, where the last file wins. Cloud
    // images commonly ship /etc/ssh/sshd_config.d/50-cloud-init.conf, which
    // sets PasswordAuthentication itself; a file named 99-panel.conf would
    // load after it and be silently overridden for every keyword both files
    // set. A low number sorts first, so the panel's values always win.
    private const DROP_IN = '00-panel.conf';

    // The name this used before that was understood. Removed on every write
    // so an upgraded box does not keep both — two files setting the same
    // keyword is exactly the failure mode above, just against ourselves.
    private const LEGACY_DROP_IN = '99-panel.conf';

    public function __construct(
        private ServerOps $serverOps,
        private Firewall $firewall,
        private ManagedFile $files,
    ) {}

    public function key(): string
    {
        return 'security';
    }

    public function available(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $effective = $this->effectiveConfig();

        return [
            'port' => (int) ($effective['port'] ?? config('server.ssh_port', 22)),
            'permit_root_login' => $effective['permitrootlogin'] ?? 'prohibit-password',
            'password_authentication' => ($effective['passwordauthentication'] ?? 'yes') === 'yes',
            // Deliberately the same predicate `apply()` guards with, not a
            // second implementation of "is there a key". It lets the UI disable
            // key-only login up front instead of accepting the choice and then
            // refusing it — and because there is one function, the greyed-out
            // control and the 422 can never disagree about why.
            'has_ssh_key' => $this->hasSshKey($effective['permitrootlogin'] ?? 'prohibit-password'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        // Lockout guard: don't let the user turn off password auth unless there
        // is at least one SSH key to get back in with.
        // Asked about the root-login setting being SAVED, not the current one:
        // switching root login off in the same save takes root's key out of
        // the ways back in.
        if (! $data['password_authentication'] && ! $this->hasSshKey((string) $data['permit_root_login'])) {
            throw ValidationException::withMessages([
                'password_authentication' => [__('errors/setting.no_ssh_key')],
            ]);
        }

        $rootLogin = in_array($data['permit_root_login'], self::ROOT_LOGIN, true)
            ? $data['permit_root_login']
            : 'prohibit-password';

        $config = "# Managed by the panel — edit via Settings, not by hand.\n"
            ."Port {$data['port']}\n"
            ."PermitRootLogin {$rootLogin}\n"
            .'PasswordAuthentication '.($data['password_authentication'] ? 'yes' : 'no')."\n"
            .$this->allowGroupsLine();

        $dir = rtrim((string) config('server.sshd_config_dir'), '/');

        // The group has to exist before a config names it. `AllowGroups` is a
        // whitelist, and a name that matches no group matches no user — so a
        // missing `ssh-users` does not fail loudly, it just quietly stops being
        // one of the ways in.
        $this->serverOps->run(
            ['groupadd', '-f', 'ssh-users'],
            ['feature' => 'setting', 'group' => 'security', 'op' => 'ssh_group_ensure'],
        );

        $write = $this->files->put(
            $dir.'/'.self::DROP_IN,
            $config,
            ['feature' => 'setting', 'group' => 'security'],
        );

        if ($write->failed()) {
            throw new SettingOperationException($write->reference);
        }

        // Refuse to reload on a bad config — the running SSH daemon stays up.
        $test = $this->serverOps->run(['sshd', '-t'], ['feature' => 'setting', 'group' => 'security', 'op' => 'test']);
        if ($test->failed()) {
            throw new SettingOperationException($test->reference);
        }

        // Only once the new file is known to parse: drop the legacy name so
        // it cannot keep outvoting this one on a box that wrote it before.
        $cleanup = $this->files->delete(
            $dir.'/'.self::LEGACY_DROP_IN,
            ['feature' => 'setting', 'group' => 'security'],
        );

        if ($cleanup->failed()) {
            throw new SettingOperationException($cleanup->reference);
        }

        // Firewall port sync: open the new SSH port BEFORE the daemon moves to
        // it, so the change can never lock the user out.
        if ($this->firewall->status()['enabled']) {
            $rule = FirewallRule::query()->firstOrCreate(
                ['port_from' => (int) $data['port'], 'port_to' => null, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => null],
                ['origin' => 'default', 'description' => 'SSH'],
            );
            $this->firewall->apply($rule);
        }

        $reload = $this->serverOps->run(['systemctl', 'reload', 'ssh'], ['feature' => 'setting', 'group' => 'security', 'op' => 'reload']);
        if ($reload->failed()) {
            throw new SettingOperationException($reload->reference);
        }
    }

    /**
     * The `AllowGroups` line, or nothing at all.
     *
     * `AllowGroups` is a whitelist over *every* account, root included, and it
     * is consulted before `PermitRootLogin` is. Writing a fixed
     * `ssh-users sudo` therefore locked root out of a server the moment anyone
     * pressed Save on this screen: root's primary group is `root`, it is not a
     * member of `sudo`, and most providers hand you a box you log into as root.
     * `sshd -t` cannot catch it — the syntax is valid — and `reload` leaves the
     * open session alive, so the lockout is discovered at the next login with
     * nothing to connect it to.
     *
     * So the list is derived rather than assumed:
     *
     *  - `ssh-users`, because that is the boundary the System User toggle moves
     *    accounts across, and without a line here that toggle enforces nothing.
     *  - `sudo` and `root`, the two ways an administrator reaches this server.
     *    Allowing root here does not permit root login — `PermitRootLogin`
     *    still decides that, and still means no when it says no.
     *  - whatever the machine already allows, so a policy the distro or the
     *    operator set is widened by us and never narrowed.
     *
     * And if the box restricts by `AllowUsers` instead, no line is written at
     * all. The two directives are ANDed, so adding ours could only take access
     * away from a list somebody else chose deliberately.
     */
    private function allowGroupsLine(): string
    {
        $effective = $this->effectiveConfig();

        if (($effective['allowusers'] ?? '') !== '') {
            return '';
        }

        $existing = preg_split('/\s+/', trim((string) ($effective['allowgroups'] ?? ''))) ?: [];

        $groups = array_values(array_unique(array_filter(array_merge(
            ['ssh-users', 'sudo', 'root'],
            $existing,
        ))));

        return 'AllowGroups '.implode(' ', $groups)."\n";
    }

    /**
     * Whether the System User "SSH access" toggle actually decides anything.
     *
     * Membership of `ssh-users` only keeps someone out once sshd carries an
     * `AllowGroups` naming it, and the only thing that writes one is saving
     * this screen. So on a fresh server the toggle reads "off" while the
     * person logs in regardless — reproduced 2026-09-23. Not fixed by writing
     * the line automatically: `AllowGroups` is a whitelist over every account,
     * and applying it unasked is how a server locks out whoever the panel
     * did not know about. Reported instead, so the screen can say so.
     *
     * Null when sshd could not be asked — "unknown" is not "no".
     */
    public function sshAccessEnforced(): ?bool
    {
        $result = $this->serverOps->run(['sshd', '-T'], ['feature' => 'setting', 'group' => 'security', 'op' => 'read']);

        if ($result->failed()) {
            return null;
        }

        $groups = preg_split('/\s+/', $this->parseEffective($result->output())['allowgroups'] ?? '') ?: [];

        return in_array(SshUsersGroup::NAME, $groups, true);
    }

    /**
     * Parse the effective sshd config (`sshd -T`) into a lowercase key map.
     *
     * @return array<string, string>
     */
    private function effectiveConfig(): array
    {
        return $this->parseEffective(
            $this->serverOps->run(['sshd', '-T'], ['feature' => 'setting', 'group' => 'security', 'op' => 'read'])->output(),
        );
    }

    /**
     * `sshd -T` prints a list keyword once PER ENTRY — `AllowGroups admins
     * devs ops` comes out as three `allowgroups` lines (measured on OpenSSH
     * 10.2). Keeping the last value per key therefore kept only `ops`, and
     * `allowGroupsLine()`, whose whole promise is "widened by us and never
     * narrowed", wrote `AllowGroups ssh-users sudo root ops` into a drop-in
     * that wins — locking out everyone in `admins` and `devs`. The list
     * keywords are joined back into the one space-separated value they were
     * written as.
     *
     * @return array<string, string>
     */
    private function parseEffective(string $output): array
    {
        $config = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = strtolower($parts[0]);
            $value = trim($parts[1]);

            $config[$key] = in_array($key, self::LIST_KEYWORDS, true) && isset($config[$key])
                ? $config[$key].' '.$value
                : $value;
        }

        return $config;
    }

    /**
     * Is there a key someone who administers this server can get back in with?
     *
     * It used to read /root/.ssh/authorized_keys with PHP's own `is_file()` —
     * as the panel account, which cannot see into /root — and look at nothing
     * else. So on an ordinary cloud server (password login already off, root's
     * key in place, the admin logging in as `ubuntu` with a key) it answered
     * "no key", and the Security screen could not be saved at all, not even
     * with the values it already had (reproduced 2026-09-23).
     *
     * Now, through ServerOps so the answer is root's:
     *  - a key the panel itself recorded;
     *  - a key for any member of `sudo` — the account a cloud image hands you;
     *  - root's own key, but only while root login is allowed, because a key
     *    for an account sshd refuses is no way back in.
     */
    private function hasSshKey(string $permitRootLogin): bool
    {
        if (SshKey::query()->exists()) {
            return true;
        }

        $homes = $permitRootLogin === 'no' ? [] : ['/root'];

        $group = $this->serverOps->run(['getent', 'group', 'sudo'], ['feature' => 'setting', 'group' => 'security', 'op' => 'sudo_members']);
        $members = array_filter(explode(',', trim((string) (explode(':', trim($group->output()))[3] ?? ''))));

        foreach ($members as $member) {
            $entry = explode(':', trim($this->serverOps->run(
                ['getent', 'passwd', $member],
                ['feature' => 'setting', 'group' => 'security', 'op' => 'sudo_member_home'],
            )->output()));

            if (($entry[5] ?? '') !== '') {
                $homes[] = $entry[5];
            }
        }

        foreach ($homes as $home) {
            if ($this->serverOps->run(
                ['test', '-s', rtrim($home, '/').'/.ssh/authorized_keys'],
                ['feature' => 'setting', 'group' => 'security', 'op' => 'key_present'],
                expectedExitCodes: [1],
            )->ok) {
                return true;
            }
        }

        return false;
    }
}
