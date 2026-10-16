<?php

namespace App\Actions\Server\Application;

use App\Models\Application;
use App\Models\Backup;
use App\Services\ActivityLogger;
use App\Services\Git\WebhookRegistrar;

/**
 * Removes the record only.
 *
 * Everything on the server — vhost, systemd unit, PHP-FPM pool, and the files
 * when the caller asked for them — is `DeprovisionApplication`'s job, and the
 * controller runs it first. Splitting them is deliberate: taking a site off
 * the panel and destroying someone's code are different decisions.
 */
class DeleteApplication
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private WebhookRegistrar $webhooks,
    ) {}

    public function execute(Application $application): void
    {
        // The webhook the panel added to the repository, if it added one —
        // otherwise every push goes on calling an endpoint that no longer
        // exists. Best effort: a provider that cannot be reached must not keep
        // a site from being deleted, and the failure is logged.
        $this->webhooks->unregister($application);

        // Its backups are archived, not deleted (decision 2026-10-16, the same
        // as a database's): kept with the site's domain, listed and
        // downloadable, until someone deletes them. Until now the rows went
        // with the site and the archives stayed in the bucket with nothing in
        // the panel pointing at them. Detached from the target first — the
        // target cascades with the site, and `backups.backup_target_id`
        // cascades from the target.
        $archived = Backup::query()->where('application_id', $application->id)->update([
            'backup_target_id' => null,
            'application_id' => null,
            'subject_name' => $application->domain ?: $application->name,
        ]);

        $this->activityLogger->log('application.deleted', $application, [
            'name' => $application->name,
            'site_type' => $application->site_type,
            'backups_archived' => $archived,
        ]);

        $application->delete();
    }
}
