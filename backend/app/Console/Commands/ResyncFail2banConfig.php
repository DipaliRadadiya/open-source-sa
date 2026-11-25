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
 * The file is written only at install and on saving the fail2ban settings, so a template fix
 * would otherwise reach new installs only. Idempotent: it reads the settings, ignore list and
 * enabled jails back out of the file and writes the same values through the current template.
 *
 * A console command, not an endpoint: it runs from the installer and updater, where no user
 * exists to authenticate as. See {@see RecordFirewallDefaults}.
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

        // No managed file: the panel never configured fail2ban here. Don't decide the jails
        // during a deploy; leave it to the settings screen.
        if ($jails === []) {
            $this->components->info('No panel-managed jail.local found — nothing to resync.');

            return self::SUCCESS;
        }

        $settings = $fail2ban->settings();

        try {
            $fail2ban->write($settings, $fail2ban->ignoreIps(), $jails);
        } catch (Fail2banException $exception) {
            // Never fatal: `write()` validates with `fail2ban-client -t` and restores the
            // previous file before throwing, so the server keeps its old configuration.
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
     * Site jails saved before the settings allowlist existed. Reported, not rewritten: the file
     * is the user's; re-saving it through the panel applies the allowlist.
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

    /**
     * Drop the `wp-admin` rule (it banned the site's own admins) from every site filter that is
     * still the old default; edited filters are left alone. Never fatal.
     */
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
