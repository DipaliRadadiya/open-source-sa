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

        $this->repairDefaultFilters($sites);
        $this->reportDisallowedJailSettings($sites);

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
     * Take the `wp-admin` rule out of every site filter that is still the old
     * default — it banned the site's own administrators (see
     * ApplicationFail2banManager::defaultFilterContent()). Changing the
     * default alone would have fixed only jails enabled from now on. Per site
     * and never fatal, like the move below; an edited filter is left alone.
     */
    /**
     * Site jails saved before the settings allowlist existed. Reported, not
     * rewritten: the file is the user's, and changing what it does during a
     * deploy is not this command's call. Re-saving it through the panel
     * applies the allowlist.
     */
    private function reportDisallowedJailSettings(ApplicationFail2banManager $sites): void
    {
        Application::query()
            ->whereNotNull('fail2ban_jail_content')
            ->orderBy('id')
            ->each(function (Application $application) use ($sites) {
                $disallowed = $sites->disallowedJailKeys((string) $application->fail2ban_jail_content);

                if ($disallowed !== []) {
                    $this->components->warn("Site jail for {$application->name} (#{$application->id}) sets ".implode(', ', $disallowed).', which site jails may no longer set. Review it and save it again from the panel.');
                }
            });
    }

    private function repairDefaultFilters(ApplicationFail2banManager $sites): void
    {
        $repaired = 0;

        Application::query()
            ->where('fail2ban_jail_name', 'like', ApplicationFail2banManager::NAME_PREFIX.'%')
            ->orderBy('id')
            ->each(function (Application $application) use ($sites, &$repaired) {
                try {
                    if ($sites->repairLegacyDefaultFilter($application)) {
                        $repaired++;
                    }
                } catch (Fail2banOperationException $exception) {
                    $this->components->warn("Site filter for {$application->name} not updated (reference {$exception->reference}); it keeps its old rules.");
                }
            });

        if ($repaired > 0) {
            $this->components->info("Site filters updated so a logged-in administrator is not banned: {$repaired}.");
        }
    }
}
