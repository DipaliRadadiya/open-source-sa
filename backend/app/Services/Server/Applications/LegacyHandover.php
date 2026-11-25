<?php

namespace App\Services\Server\Applications;

use App\Enums\SupervisorMode;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\View;

/**
 * The server-side half of taking a migrated box over.
 *
 * Sync only records the applications the old panel's PM2 runs (`Discoverable` forbids writing to
 * the server). The three writes the migration still needs live here, behind an explicit action.
 * None of them restarts an application, so they are safe on a working server.
 *
 * 1. Retire the old agent: stop and disable its service, nothing more. Never use the agent's own
 *    API: its teardown runs `pm2 unstartup` and `pm2 kill`, which stops every application.
 * 2. Repair boot persistence: write the systemd unit from known values and verify it with
 *    `systemctl`, instead of scraping the sudo line `pm2 startup` prints.
 * 3. Rotate PM2's logs: the old panel installed `pm2-logrotate` into root's daemon, while the
 *    applications run under per-user ones, so `~/.pm2/logs` never rotated.
 */
class LegacyHandover
{
    public function __construct(
        private ServerOps $serverOps,
        private ManagedFile $files,
        private LegacyAgentDetector $agent,
    ) {}

    /**
     * Run all three, for every account that has an adopted application.
     *
     * @return array{agent: ?string, users: array<int, string>}
     */
    public function complete(): array
    {
        $agent = $this->retireAgent();
        $users = [];

        foreach ($this->pm2Users() as $user) {
            $users[] = [
                'username' => $user->username,
                'boot_unit' => $this->ensureBootPersistence($user),
                'log_rotation' => $this->ensureLogRotation($user) ? 'ok' : 'failed',
            ];
        }

        // Every step is checked, so a failure is not reported as success.
        $ok = ($agent === null || ($agent['stopped'] && $agent['disabled']))
            && collect($users)->every(fn (array $u) => $u['boot_unit'] !== 'failed' && $u['log_rotation'] === 'ok');

        return ['ok' => $ok, 'agent' => $agent, 'users' => $users];
    }

    /**
     * Whether a handover is needed: the same checks {@see complete()} acts on, without acting.
     *
     * @return array{needed: bool, agent: array{unit: ?string, running: bool}, users: list<array{username: string, boot_unit_healthy: bool, log_rotation: bool}>}
     */
    public function status(): array
    {
        $found = $this->agent->describe();
        $users = [];

        foreach ($this->pm2Users() as $user) {
            $users[] = [
                'username' => $user->username,
                'boot_unit_healthy' => $this->bootUnitHealthy($user),
                'log_rotation' => $this->serverOps->probe(
                    ['test', '-f', $this->logrotatePath($user)],
                    ['feature' => 'application', 'op' => 'pm2_logrotate_present', 'user' => $user->username],
                )->ok,
            ];
        }

        $needed = (bool) $found['running']
            || collect($users)->contains(fn (array $u) => ! $u['boot_unit_healthy'] || ! $u['log_rotation']);

        return [
            'needed' => $needed,
            'agent' => ['unit' => $found['unit'], 'running' => (bool) $found['running']],
            'users' => $users,
        ];
    }

    /** @return Collection<int, SystemUser> */
    private function pm2Users()
    {
        return SystemUser::query()
            ->whereIn('id', Application::query()
                ->where('supervisor_mode', SupervisorMode::Pm2)
                ->select('system_user_id'))
            ->get();
    }

    /**
     * Stop and disable the old agent's service; null when there is none (not an error).
     */
    /**
     * @return array{unit: string, stopped: bool, disabled: bool, reference: ?string}|null
     */
    public function retireAgent(): ?array
    {
        $found = $this->agent->describe();
        $unit = $found['unit'];

        if ($unit === null) {
            return null;
        }

        $context = ['feature' => 'application', 'op' => 'legacy_agent_retire', 'unit' => $unit];

        $stopped = $this->serverOps->run(['systemctl', 'stop', $unit], $context);
        $disabled = $this->serverOps->run(['systemctl', 'disable', $unit], $context);

        return [
            'unit' => $unit,
            'stopped' => $stopped->ok,
            'disabled' => $disabled->ok,
            'reference' => $stopped->failed() ? $stopped->reference : ($disabled->failed() ? $disabled->reference : null),
        ];
    }

    /**
     * Make sure this account's PM2 daemon comes back after a reboot.
     *
     * A working enabled unit is left alone (the customer's own `pm2 startup`); one whose `pm2`
     * binary is gone (a Node upgrade does that) is rewritten.
     */
    /**
     * @return 'healthy'|'written'|'failed'
     */
    public function ensureBootPersistence(SystemUser $user): string
    {
        if ($this->bootUnitHealthy($user)) {
            return 'healthy';
        }

        $context = ['feature' => 'application', 'op' => 'pm2_boot_unit', 'user' => $user->username];

        if ($this->files->put($this->bootUnitPath($user), $this->renderBootUnit($user), $context)->failed()
            || $this->serverOps->run(['systemctl', 'daemon-reload'], $context)->failed()
            || $this->serverOps->run(['systemctl', 'enable', $this->bootUnit($user)], $context)->failed()) {
            return 'failed';
        }

        // Deliberately not started: `pm2 resurrect` against the running daemon would start a
        // second copy of every application. The unit takes effect at the next boot.
        return 'written';
    }

    /**
     * A rotation policy for `~/.pm2/logs`.
     *
     * `copytruncate`: PM2 keeps its log files open, so a rename would leave it writing to an
     * unreachable inode.
     */
    public function ensureLogRotation(SystemUser $user): bool
    {
        return $this->files->put(
            $this->logrotatePath($user),
            $this->renderLogRotation($user),
            ['feature' => 'application', 'op' => 'pm2_logrotate', 'user' => $user->username],
        )->ok;
    }

    public function bootUnit(SystemUser $user): string
    {
        return 'pm2-'.$user->username.'.service';
    }

    public function bootUnitPath(SystemUser $user): string
    {
        $dir = rtrim((string) config('server.applications.systemd_dir', '/etc/systemd/system'), '/');

        return $dir.'/'.$this->bootUnit($user);
    }

    public function logrotatePath(SystemUser $user): string
    {
        return '/etc/logrotate.d/sv-pm2-'.$user->username;
    }

    /**
     * Enabled, and pointing at a `pm2` that exists.
     *
     * The binary check catches a unit that survived a Node upgrade and names a path that is gone,
     * which would fail silently at boot.
     */
    private function bootUnitHealthy(SystemUser $user): bool
    {
        $enabled = $this->serverOps->probe(
            ['systemctl', 'is-enabled', '--quiet', $this->bootUnit($user)],
            ['feature' => 'application', 'op' => 'pm2_boot_enabled', 'user' => $user->username],
        );

        if (! $enabled->ok) {
            return false;
        }

        return $this->serverOps->probe(
            ['test', '-x', $this->pm2Binary()],
            ['feature' => 'application', 'op' => 'pm2_boot_binary', 'user' => $user->username],
        )->ok;
    }

    private function pm2Binary(): string
    {
        return (string) config('server.applications.pm2_binary', 'pm2');
    }

    private function pm2Home(SystemUser $user): string
    {
        return rtrim((string) $user->home_path, '/').'/.pm2';
    }

    private function renderBootUnit(SystemUser $user): string
    {
        return View::make('server.units.pm2-boot', [
            'user' => $user->username,
            'pm2Home' => $this->pm2Home($user),
            'pm2' => $this->pm2Binary(),
        ])->render();
    }

    private function renderLogRotation(SystemUser $user): string
    {
        $dir = $this->pm2Home($user).'/logs';
        $name = $user->username;

        return <<<CONF
        # Managed by the panel. PM2's own logs, which the old panel never
        # rotated: it installed pm2-logrotate into root's daemon while every
        # application runs under a per-user one.
        {$dir}/*.log {
            daily
            rotate 14
            maxsize 50M
            missingok
            notifempty
            compress
            delaycompress
            # PM2 holds these open for the life of the daemon — a rename would
            # leave it writing to an unreachable inode.
            copytruncate
            # The files belong to the account that runs the daemon, so
            # logrotate has to act as it rather than as root.
            su {$name} {$name}
            create 0640 {$name} {$name}
        }

        CONF;
    }
}
