<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\Server\Applications\SecretFilePrivacy;
use Illuminate\Console\Command;

/**
 * Re-tightens the secret files that an application loosens by itself.
 *
 * `sites:resync` narrows every site's secrets at deploy time, and that holds
 * for files nothing rewrites. Joomla and Nextcloud rewrite theirs whenever
 * their own settings are saved, and loosen them each time — see
 * SecretFilePrivacy::REWRITTEN_BY_APP. Only those types, and only active
 * sites: a narrow is a few commands per site, and every other type has
 * nothing that needs sweeping.
 *
 * The window this leaves is up to five minutes, readable by the web server
 * account only — every home directory is closed to other site users.
 */
class NarrowAppSecrets extends Command
{
    protected $signature = 'sites:narrow-app-secrets';

    protected $description = 'Re-tighten secret files that Joomla and Nextcloud loosen when they save their settings';

    public function handle(SecretFilePrivacy $privacy): int
    {
        $applications = Application::query()
            ->whereIn('site_type', SecretFilePrivacy::REWRITTEN_BY_APP)
            ->where('status', ApplicationStatus::Active)
            ->with('systemUser')
            ->get();

        foreach ($applications as $application) {
            // Best-effort and never throws: one unreachable site must not
            // stop the sweep for the rest.
            $privacy->narrow($application);
        }

        $this->info("Secret files narrowed for {$applications->count()} site(s).");

        return self::SUCCESS;
    }
}
