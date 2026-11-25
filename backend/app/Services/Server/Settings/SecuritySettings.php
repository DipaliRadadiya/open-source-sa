<?php

namespace App\Services\Server\Settings;

use App\Contracts\Firewall;
use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Models\FirewallRule;
use App\Models\SshKey;
use App\Services\Server\Fail2ban\Fail2banManager;
use App\Services\Server\Firewall\ListeningPorts;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\SystemUsers\SshUsersGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    // sshd's Include takes the FIRST value seen for each keyword (the opposite of systemd drop-ins).
    // Cloud images ship 50-cloud-init.conf, so a `99-` file would be silently overridden; a low
    // number sorts first, so the panel's values win.
    private const DROP_IN = '00-panel.conf';

    // The previous name. Removed on every write: two files setting the same keyword is the failure above.
    private const LEGACY_DROP_IN = '99-panel.conf';

    // See port(). Holds the port and whether passwords are accepted.
    private const PORT_CACHE = 'server.ssh_login.effective';

    public function __construct(
        private ServerOps $serverOps,
        private Firewall $firewall,
        private ManagedFile $files,
        private ListeningPorts $listening,
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
            // The same predicate `apply()` guards with, so the greyed-out control and the 422
            // can never disagree.
            'has_ssh_key' => $this->hasSshKey($effective['permitrootlogin'] ?? 'prohibit-password'),
            // Whether Save will also start enforcing the System Users' SSH access switch: it writes
            // `AllowGroups` (see allowGroupsLine()), after which an account not in ssh-users, sudo
            // or root cannot log in. Lets the confirmation say so.
            'save_enforces_ssh_access' => ($effective['allowusers'] ?? '') === ''
                && ! in_array('ssh-users', preg_split('/\s+/', trim((string) ($effective['allowgroups'] ?? ''))) ?: [], true),
        ];
    }

    /**
     * The port SSH listens on, and nothing else.
     *
     * For the Command-line access card on every Docker app's dashboard. `read()` shells out to
     * `sshd -T` and looks for keys; this reads the port alone and caches it for five minutes
     * (`apply()` drops the cache).
     */
    public function port(): int
    {
        return $this->loginSummary()['port'];
    }

    /**
     * Whether sshd accepts passwords, from the same cached read as port().
     *
     * Lets the card offer the password option only where it would work.
     */
    public function passwordLogin(): bool
    {
        return $this->loginSummary()['password_authentication'];
    }

    /**
     * @return array{port: int, password_authentication: bool}
     */
    private function loginSummary(): array
    {
        // A new key: the old `server.ssh_port.effective` held the bare port, and a value cached by
        // the previous release must not be read back as this shape.
        return Cache::remember(self::PORT_CACHE, now()->addMinutes(5), function (): array {
            $effective = $this->effectiveConfig();

            return [
                'port' => (int) ($effective['port'] ?? config('server.ssh_port', 22)),
                'password_authentication' => ($effective['passwordauthentication'] ?? 'yes') === 'yes',
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        // Lockout guard: don't turn off password auth unless there is an SSH key to get back in
        // with. Asked about the root-login setting being SAVED: turning root login off in the same
        // save removes root's key from the ways back in.
        if (! $data['password_authentication'] && ! $this->hasSshKey((string) $data['permit_root_login'])) {
            throw ValidationException::withMessages([
                'password_authentication' => [__('errors/setting.no_ssh_key')],
            ]);
        }

        // Read before the drop-in changes it: only a port move needs the socket moved too.
        $previousPort = (int) ($this->effectiveConfig()['port'] ?? config('server.ssh_port', 22));
        $portChanged = (int) $data['port'] !== $previousPort;

        if ($portChanged) {
            $this->assertPortFree((int) $data['port']);
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

        // The group must exist before a config names it: `AllowGroups` is a whitelist, and a name
        // that matches no group quietly stops being one of the ways in.
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

        // `sshd -T` reads the files, not the running daemon, so it answers with the new port from here on.
        Cache::forget(self::PORT_CACHE);

        // Refuse to reload on a bad config — the running SSH daemon stays up.
        $test = $this->serverOps->run(['sshd', '-t'], ['feature' => 'setting', 'group' => 'security', 'op' => 'test']);
        if ($test->failed()) {
            throw new SettingOperationException($test->reference);
        }

        // Only once the new file is known to parse: drop the legacy name so it cannot outvote this one.
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

            // Moving back to a port SSH left earlier finds its rule released as an ordinary one
            // (releaseOldPortRule()); it is the panel's SSH rule again.
            if ($rule->origin === 'user') {
                $rule->forceFill(['origin' => 'default'])->save();
            }

            $this->firewall->apply($rule);
        }

        // try-reload-or-restart, not reload: under socket activation ssh.service may not be running,
        // and a failing `reload` would stop a save before moveSocket(), the only step that revives a dead socket.
        $reload = $this->serverOps->run(['systemctl', 'try-reload-or-restart', 'ssh'], ['feature' => 'setting', 'group' => 'security', 'op' => 'reload']);
        if ($reload->failed()) {
            throw new SettingOperationException($reload->reference);
        }

        $this->moveSocket($portChanged, $dir.'/'.self::DROP_IN, $config, $previousPort);

        if ($portChanged) {
            $this->releaseOldPortRule($previousPort);
            $this->moveFail2banJail();
        }
    }

    /**
     * Point the SSH jail at the port SSH now listens on: jail.local resolves `{ssh_port}` when it
     * is written, so after a move it would keep banning port 22.
     *
     * Last, and never fatal: SSH has already moved, and failing the save over the jail would
     * report a change that did happen as one that did not. Logged instead.
     */
    private function moveFail2banJail(): void
    {
        $fail2ban = app(Fail2banManager::class);

        try {
            if (! $fail2ban->installed() || ($jails = $fail2ban->configuredJails()) === []) {
                return;
            }

            $fail2ban->write($fail2ban->settings(), $fail2ban->ignoreIps(), $jails);

            // A running jail keeps its firewall rule on the old port until restarted.
            $fail2ban->restartSshJails();
        } catch (Throwable $exception) {
            Log::channel('server-ops')->error('fail2ban jail not moved to the new SSH port', [
                'feature' => 'setting', 'group' => 'security', 'op' => 'fail2ban_ssh_port',
                'exception' => $exception::class, 'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Refuse a port something else already listens on.
     *
     * ssh.socket binds the port itself; a taken one fails it with "Address already in use" after
     * the old port is released, leaving no SSH until a reboot. sshd's own listener (or systemd
     * holding it for ssh.socket) is not a conflict.
     */
    private function assertPortFree(int $port): void
    {
        foreach ($this->listening->all() as $listener) {
            if ($listener['protocol'] !== 'tcp' || $listener['port'] !== $port) {
                continue;
            }

            if (in_array($listener['program'], ['sshd', 'systemd'], true)) {
                continue;
            }

            throw ValidationException::withMessages([
                'port' => [__('errors/setting.ssh_port_in_use', ['port' => $port])],
            ]);
        }
    }

    /**
     * Close the port SSH has just left, as v7 does.
     *
     * The rule opened for SSH is the panel's to close. Connections already open survive (ufw keeps
     * established traffic). A rule the user made for that port stays; if ufw refuses the delete,
     * the rule is released to the user. SshLockoutGuard still protects the port SSH is on now.
     */
    private function releaseOldPortRule(int $port): void
    {
        if (in_array($port, [80, 443], true)) {
            return;
        }

        $rules = FirewallRule::query()
            ->where('port_from', $port)
            ->whereNull('port_to')
            ->where('protocol', 'tcp')
            ->where('action', 'allow')
            ->whereNull('source_ip')
            ->where('origin', '!=', 'user')
            ->get();

        // Through ufw even while it is off: it keeps its rules when disabled, and a row deleted
        // here alone would come back open on enable.
        foreach ($rules as $rule) {
            if ($this->firewall->remove($rule)->ok) {
                $rule->delete();
            } else {
                $rule->forceFill(['origin' => 'user'])->save();
            }
        }
    }

    /**
     * Move a socket-activated SSH to the new port.
     *
     * Ubuntu 24.04+ starts sshd from `ssh.socket`, whose port is generated from sshd_config by
     * `sshd-socket-generator` at daemon-reload; reloading ssh alone does not change where it
     * listens. daemon-reload regenerates the addresses and a restart of the socket applies them.
     * Open sessions survive (ssh.service is KillMode=process). The firewall was opened above.
     */
    private function moveSocket(bool $portChanged, string $dropIn, string $config, int $previousPort): void
    {
        $context = ['feature' => 'setting', 'group' => 'security', 'op' => 'ssh_socket'];

        $state = trim($this->serverOps->run(['systemctl', 'is-active', 'ssh.socket'], $context)->output());

        // A failed socket is restarted whatever the port (SSH is down, and saving here is the only
        // repair offered). A merely inactive socket means no socket activation: left alone.
        if (! ($state === 'failed' || ($state === 'active' && $portChanged))) {
            return;
        }

        $failure = $this->restartSocket($context);

        if ($failure === null) {
            return;
        }

        // The new port would not bind: put the old one back and bring the socket up on it before
        // reporting, so a refused move does not leave a server with no SSH.
        if ($portChanged) {
            $this->files->put(
                $dropIn,
                (string) preg_replace('/^Port \d+$/m', "Port {$previousPort}", $config),
                ['feature' => 'setting', 'group' => 'security', 'op' => 'ssh_rollback'],
            );
            $this->restartSocket($context);
        }

        throw new SettingOperationException($failure);
    }

    /**
     * daemon-reload regenerates the socket's addresses from sshd_config and
     * the restart applies them. Returns the failing step's reference.
     *
     * @param  array<string, string>  $context
     */
    private function restartSocket(array $context): ?string
    {
        foreach ([['systemctl', 'daemon-reload'], ['systemctl', 'restart', 'ssh.socket']] as $command) {
            $result = $this->serverOps->run($command, $context);

            if ($result->failed()) {
                return $result->reference;
            }
        }

        return null;
    }

    /**
     * The `AllowGroups` line, or nothing at all.
     *
     * `AllowGroups` is a whitelist over *every* account, root included, and is consulted before
     * `PermitRootLogin`. A fixed `ssh-users sudo` would lock root out (its group is `root`), and
     * `sshd -t` cannot catch it: the lockout shows at the next login. So the list is derived:
     *
     *  - `ssh-users`, the boundary the System User toggle moves accounts across;
     *  - `sudo` and `root`, the ways an administrator reaches the server (`PermitRootLogin`
     *    still decides whether root may log in);
     *  - whatever the machine already allows, so existing policy is widened, never narrowed.
     *
     * If the box restricts by `AllowUsers`, no line is written: the two are ANDed, so ours could
     * only take access away from a list somebody chose deliberately.
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
     * `ssh-users` membership only keeps someone out once sshd carries an `AllowGroups` naming it,
     * and only saving this screen writes one. Not written automatically: it is a whitelist over
     * every account, and applying it unasked can lock out accounts the panel does not know about.
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
     * `sshd -T` prints a list keyword once PER ENTRY (`AllowGroups admins devs ops` is three
     * `allowgroups` lines). Keeping only the last value would make `allowGroupsLine()` drop
     * `admins` and `devs` and lock them out, so list keywords are joined back into one
     * space-separated value.
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
     * Read through ServerOps, so the answer is root's (the panel account cannot see /root):
     *  - a key the panel itself recorded, for a user who can still log in;
     *  - a key for any member of `sudo` — the account a cloud image hands you;
     *  - root's own key, but only while root login is allowed, because a key
     *    for an account sshd refuses is no way back in.
     */
    private function hasSshKey(string $permitRootLogin): bool
    {
        // Only a key whose owner sshd will still let in: the same save writes
        // `AllowGroups ssh-users sudo root`, so a key for a System User with SSH access off
        // (not in ssh-users or sudo) or a no-login shell is no way back.
        $usableKey = SshKey::query()
            ->whereHas('systemUser', fn ($user) => $user
                ->where(fn ($access) => $access->where('ssh_access', true)->orWhere('sudo', true))
                ->where(fn ($shell) => $shell->whereNull('shell')
                    ->orWhere(fn ($real) => $real->where('shell', 'not like', '%nologin')->where('shell', 'not like', '%/false'))))
            ->exists();

        if ($usableKey) {
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
