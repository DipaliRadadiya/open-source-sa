<?php

namespace App\Services\Server\Applications;

use App\Enums\SupervisorMode;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\View;

/**
 * The server-side half of taking a migrated box over.
 *
 * Sync finds the applications the old panel's PM2 is running and records them,
 * and stops there — `Discoverable` forbids a discoverer from writing to the
 * server, which is right: these are the customer's live sites and pressing Sync
 * must never be frightening. The three writes the migration still needs live
 * here, behind an action someone chooses.
 *
 * None of them restarts an application. They are safe to run on a working
 * server, which is the point: the customer keeps their sites on PM2 for as long
 * as they like, and this makes that state a supported one rather than a
 * half-migrated one.
 *
 * **1. Retire the old agent.** Stop and disable its service, nothing more.
 * Never through the agent's own API: its system-user teardown runs
 * `pm2 unstartup` and then `pm2 kill`, which would stop every application the
 * user owns. The applications are children of the per-user PM2 daemon, not of
 * the agent, so stopping the agent does not touch them.
 *
 * **2. Repair boot persistence.** `pm2 startup` prints a sudo line for a human
 * to paste, and the old panel scraped it with a regex that a Node upgrade or a
 * hyphenated username defeats — after which it ran the empty string, got exit
 * 0, and reported success. Some fraction of migrated servers therefore have
 * applications that will not come back from a reboot and nobody knows. The unit
 * is written from values we already hold and verified with `systemctl`.
 *
 * **3. Give PM2's logs a rotation policy.** The old panel installed
 * `pm2-logrotate` into *root's* daemon while every application runs under a
 * per-user one, so `~/.pm2/logs` has never rotated on any of these servers.
 * On an old box with a chatty application this is the failure that fills the
 * disk and takes down MySQL and every other site with it.
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
        $retired = $this->retireAgent();

        $users = SystemUser::query()
            ->whereIn('id', Application::query()
                ->where('supervisor_mode', SupervisorMode::Pm2)
                ->select('system_user_id'))
            ->get();

        foreach ($users as $user) {
            $this->ensureBootPersistence($user);
            $this->ensureLogRotation($user);
        }

        return [
            'agent' => $retired,
            'users' => $users->pluck('username')->all(),
        ];
    }

    /**
     * Stop and disable the old agent's service.
     *
     * Returns the unit it acted on, or null when there was nothing running —
     * which is the normal case on a server that was never migrated, and not an
     * error.
     */
    public function retireAgent(): ?string
    {
        $found = $this->agent->describe();
        $unit = $found['unit'];

        if ($unit === null) {
            return null;
        }

        $context = ['feature' => 'application', 'op' => 'legacy_agent_retire', 'unit' => $unit];

        $this->serverOps->run(['systemctl', 'stop', $unit], $context);
        $this->serverOps->run(['systemctl', 'disable', $unit], $context);

        return $unit;
    }

    /**
     * Make sure this account's PM2 daemon comes back after a reboot.
     *
     * Left alone when a working unit is already enabled — a customer's own
     * `pm2 startup` is not ours to rewrite. Rewritten when it is enabled but
     * its `pm2` is gone, which is what a Node upgrade through `n` does to a
     * unit written before it.
     */
    public function ensureBootPersistence(SystemUser $user): bool
    {
        if ($this->bootUnitHealthy($user)) {
            return false;
        }

        $context = ['feature' => 'application', 'op' => 'pm2_boot_unit', 'user' => $user->username];

        $this->files->put($this->bootUnitPath($user), $this->renderBootUnit($user), $context);

        $this->serverOps->run(['systemctl', 'daemon-reload'], $context);
        $this->serverOps->run(['systemctl', 'enable', $this->bootUnit($user)], $context);

        // Deliberately not started. The daemon is already running — that is
        // what is serving the customer's sites — and `pm2 resurrect` against a
        // live daemon would start a second copy of every application in the
        // dump. Enabling is the whole job; the unit takes effect at the next
        // boot, which is the moment it is for.
        return true;
    }

    /**
     * A rotation policy for `~/.pm2/logs`, which has never had one.
     *
     * `copytruncate` because PM2 holds its log files open for the life of the
     * daemon, so a rename would leave it writing to an unreachable inode — the
     * same reason the applications' own policy uses it.
     */
    public function ensureLogRotation(SystemUser $user): void
    {
        $this->files->put(
            $this->logrotatePath($user),
            $this->renderLogRotation($user),
            ['feature' => 'application', 'op' => 'pm2_logrotate', 'user' => $user->username],
        );
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
     * Both asked of the system rather than of PM2's own output. `is-enabled`
     * is systemd's answer to the only question that matters here, and the
     * binary check catches the unit that survived a Node upgrade and now names
     * a path that is gone — which fails at boot, silently, months later.
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
