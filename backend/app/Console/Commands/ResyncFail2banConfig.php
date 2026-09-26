<?php

namespace App\Console\Commands;

use App\Exceptions\Server\Application\Fail2banOperationException;
use App\Exceptions\Server\Fail2ban\Fail2banException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationFail2banManager;
use App\Services\Server\Fail2ban\Fail2banManager;
use Illuminate\Console\Command;

/**
 * Rewrites the panel's `jail.local` from its own current contents.
 *
 * `jail.local` is written at install and when somebody saves the fail2ban
 * settings screen, and nowhere else. So a release that corrects how that file
 * is rendered reaches new installs only, and every existing server keeps the
 * file it was given — which is how a fix ships and repairs nothing.
 *
 * That is exactly what happened here. The template carried `backend = systemd`
 * inside `[DEFAULT]`, which applies to every jail, and the systemd backend
 * makes fail2ban ignore `logpath` and read the journal instead. Every
 * file-watching jail the panel writes — `recidive`, and every per-site
 * application jail — was therefore watching a file fail2ban never opened,
 * matching nothing and banning nobody, while the panel reported them enabled.
 * Removing the line fixes the render; this command is what makes the fix
 * arrive on a server that already exists.
 *
 * Idempotent: it reads the settings, the ignore list and the enabled jails
 * back out of the file and writes the same values through the current
 * template. Nothing the operator chose is changed.
 *
 * A console command rather than an endpoint because it runs from the installer
 * and the updater, where no user exists to authenticate as — the same
 * reasoning as {@see RecordFirewallDefaults}.
 */
class ResyncFail2banConfig extends Command
{
    protected $signature = 'fail2ban:resync';

    protected $description = "Rewrite fail2ban's managed jail.local through the current template, preserving its settings";

    public function handle(Fail2banManager $fail2ban, ApplicationFail2banManager $sites): int
    {
        if (! $fail2ban->installed()) {
            $this->components->info('fail2ban is not installed — nothing to resync.');

            return self::SUCCESS;
        }

        $this->moveSiteJails($sites);

        $jails = $fail2ban->configuredJails();

        // No managed file means the panel has never configured fail2ban on
        // this server. Writing one here would be the panel deciding, during a
        // deploy, which jails a server runs — and with an empty jail list that
        // decision is "none". Leave it to the settings screen.
        if ($jails === []) {
            $this->components->info('No panel-managed jail.local found — nothing to resync.');

            return self::SUCCESS;
        }

        $settings = $fail2ban->settings();

        try {
            $fail2ban->write($settings, $fail2ban->ignoreIps(), $jails);
        } catch (Fail2banException $exception) {
            // Never fatal. `write()` validates with `fail2ban-client -t` and
            // restores the previous file before throwing, so the server still
            // has the configuration it had a moment ago — and failing a whole
            // deploy over a config the box has already rejected would turn a
            // fixable problem into an outage.
            $this->components->warn('fail2ban config not resynced: '.$exception->getMessage());

            return self::SUCCESS;
        }

        $on = array_keys(array_filter($jails));

        $this->components->info(sprintf(
            'Rewrote jail.local through the current template (%d jail(s), enabled: %s).',
            count($jails),
            $on === [] ? '—' : implode(', ', $on),
        ));

        return self::SUCCESS;
    }

    /**
     * Move every site jail still under its bare slug to the prefixed name.
     *
     * Here because this command already runs on every panel update, and a
     * fix that only changes what the next save writes repairs no server that
     * already has a site called `sshd`. Per site and never fatal: one jail
     * that cannot be moved keeps working under its old name, and must not
     * stop the rest or fail the deploy.
     */
    private function moveSiteJails(ApplicationFail2banManager $sites): void
    {
        $moved = 0;

        Application::query()
            ->whereNotNull('fail2ban_jail_name')
            ->where('fail2ban_jail_name', 'not like', ApplicationFail2banManager::NAME_PREFIX.'%')
            ->orderBy('id')
            ->each(function (Application $application) use ($sites, &$moved) {
                try {
                    $result = $sites->migrateLegacy($application);
                } catch (Fail2banOperationException $exception) {
                    $this->components->warn("Site jail for {$application->name} not moved (reference {$exception->reference}); it keeps its old name.");

                    return;
                }

                if ($result['moved']) {
                    $moved++;
                }

                if ($result['damaged_filter'] !== null) {
                    $this->components->warn(
                        "{$result['damaged_filter']} belongs to the fail2ban package and had been overwritten by the site "
                        ."{$application->name}. It was left in place; restore it with: "
                        .'apt-get install --reinstall -o Dpkg::Options::=--force-confmiss fail2ban (after moving the file aside).'
                    );
                }
            });

        if ($moved > 0) {
            $this->components->info("Site jails moved to prefixed names: {$moved}.");
        }
    }
}
