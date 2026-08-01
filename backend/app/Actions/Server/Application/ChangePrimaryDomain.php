<?php

namespace App\Actions\Server\Application;

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Services\ActivityLogger;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Promote one of an application's names to be the canonical one.
 *
 * Its own action rather than a field update, because the primary domain is not
 * only a label: the vhost file and both log files are named after it. Changing
 * it renames all three, so the *old* configuration has to be taken out
 * deliberately — otherwise the box ends up with two vhosts claiming the same
 * site and whichever the web server reads first wins.
 */
class ChangePrimaryDomain
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Application $application, ApplicationDomain $domain): Application
    {
        $previous = $application->domain;

        if ($domain->domain === $previous) {
            return $application;
        }

        // Remove the configuration under the old name *first*, while the
        // application still knows what that name was. `configPath()` is built
        // from `applications.domain`, so once the mirror moves there is no way
        // left to address the old file.
        $this->webServers->driver()->remove($application);

        $application->domains()
            ->where('type', DomainType::Primary->value)
            ->update(['type' => DomainType::Alias->value]);

        $domain->update(['type' => DomainType::Primary]);

        // The mirror. Everything downstream — vhost filename, log paths, the
        // application resource — reads this rather than the domains table.
        $application->update(['domain' => $domain->domain]);

        $this->vhost->execute($application->fresh(['domains']));

        // Its own verb, not a generic "updated": the site's canonical URL just
        // changed, which for a CMS also means its stored URLs are now wrong.
        $this->activityLogger->log('application.primary_domain_changed', $application, [
            'from' => (string) $previous,
            'to' => $domain->domain,
        ]);

        return $application->refresh();
    }
}
