<?php

namespace App\Services\Server\Settings;

use App\Contracts\Firewall;
use App\Contracts\SettingGroup;
use App\Exceptions\Server\Setting\SettingOperationException;
use App\Models\FirewallRule;
use App\Models\SshKey;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
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
            'has_ssh_key' => $this->hasSshKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(array $data): void
    {
        // Lockout guard: don't let the user turn off password auth unless there
        // is at least one SSH key to get back in with.
        if (! $data['password_authentication'] && ! $this->hasSshKey()) {
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
            .'PasswordAuthentication '.($data['password_authentication'] ? 'yes' : 'no')."\n";

        $dir = rtrim((string) config('server.sshd_config_dir'), '/');

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
     * Parse the effective sshd config (`sshd -T`) into a lowercase key map.
     *
     * @return array<string, string>
     */
    private function effectiveConfig(): array
    {
        $output = $this->serverOps->run(['sshd', '-T'], ['feature' => 'setting', 'group' => 'security', 'op' => 'read'])->output();

        $config = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            if (count($parts) === 2) {
                $config[strtolower($parts[0])] = trim($parts[1]);
            }
        }

        return $config;
    }

    private function hasSshKey(): bool
    {
        return SshKey::query()->exists()
            || (is_file('/root/.ssh/authorized_keys') && trim((string) @file_get_contents('/root/.ssh/authorized_keys')) !== '');
    }
}
