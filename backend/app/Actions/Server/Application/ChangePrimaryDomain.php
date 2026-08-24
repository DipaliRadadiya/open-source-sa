<?php

namespace App\Actions\Server\Application;

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\InstallerManager;
use Throwable;

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
        private ApplyVhost $vhost,
        private ActivityLogger $activityLogger,
        private InstallerManager $installers,
    ) {}

    public function execute(Application $application, ApplicationDomain $domain): Application
    {
        $previous = $application->domain;

        if ($domain->domain === $previous) {
            return $application;
        }

        $previousPrimary = $application->domains()
            ->where('type', DomainType::Primary->value)
            ->firstOrFail();
        $previousUrl = $application->fresh(['certificate'])->url();
        $certificate = $application->certificate;
        $scheme = $certificate?->servable() && $certificate->covers($domain->domain)
            ? 'https'
            : 'http';

        // The new name is already an alias on this vhost, so it is reachable
        // while the application's own canonical setting is reconciled.
        $this->installers->syncUrl(
            $application->fresh(['systemUser', 'certificate']),
            $scheme.'://'.$domain->domain,
        );

        try {
            $previousPrimary->update(['type' => DomainType::Alias]);
            $domain->update(['type' => DomainType::Primary]);

            // The mirror. Everything downstream — vhost filename, log paths,
            // the application resource — reads this value.
            $application->update(['domain' => $domain->domain]);

            $this->vhost->execute($application->fresh(['domains', 'certificate']));
        } catch (Throwable $exception) {
            $domain->update(['type' => DomainType::Alias]);
            $previousPrimary->update(['type' => DomainType::Primary]);
            $application->update(['domain' => $previous]);

            try {
                $restored = $application->fresh(['domains', 'certificate', 'systemUser']);
                $this->installers->syncUrl($restored, $previousUrl);
                $this->vhost->execute($restored);
            } catch (Throwable) {
                // Preserve the transition's original failure reference.
            }

            throw $exception;
        }

        // Its own verb, not a generic "updated": the site's canonical URL just
        // changed, which for a CMS also means its stored URLs are now wrong.
        $this->activityLogger->log('application.primary_domain_changed', $application, [
            'from' => (string) $previous,
            'to' => $domain->domain,
        ]);

        return $application->refresh();
    }
}
