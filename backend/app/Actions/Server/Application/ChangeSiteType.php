<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Http\Requests\Server\Application\UpdateSiteTypeRequest;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Applications\SiteTypeDetector;

class ChangeSiteType
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ApplyVhost $vhost,
        private SiteTypeDetector $detector,
    ) {}

    /**
     * Record what is actually installed in this site.
     *
     * The transition itself was authorised by {@see UpdateSiteTypeRequest},
     * which also proved the evidence for a widening change. This applies it.
     *
     * 🔴 **Not only a column write, and that is easy to miss.** On
     * OpenLiteSpeed the site type is rendered *into the vhost*:
     * `OlsDriver::viewData()` sets `readsHtaccess` from it, because LiteSpeed
     * Cache talks to the cache module through `.htaccess` and nothing else. So
     * a site relabelled to WordPress without republishing its config gets the
     * WordPress screens while the cache integration silently never happens —
     * the exact class of half-applied change this panel keeps being bitten by.
     *
     * Republished for every web server rather than only for OpenLiteSpeed: the
     * templates are free to read `site_type` and one of them already does, so
     * a driver-specific branch here would be a bug waiting for the next
     * template edit. `ApplyVhost` keeps the previous config and rolls back a
     * rejected one, so the cost of republishing when nothing changed is a
     * config test.
     *
     * @throws ProvisioningFailedException
     */
    public function execute(Application $application, string $siteType): Application
    {
        $from = (string) $application->site_type;

        $verdict = $this->detector->detect($application);

        $application->update([
            'site_type' => $siteType,
            // The evidence, kept where anyone acting on this site will see it
            // — the same reasoning, and the same corner of `settings`, that
            // brownfield adoption uses for its own guess. A site type somebody
            // set by hand and one the panel installed are indistinguishable
            // from the column alone, and the difference is the first thing
            // worth knowing when a site misbehaves.
            'settings' => array_merge($application->settings ?? [], [
                'type_detection' => [
                    'changed_from' => $from,
                    'detected' => $verdict->siteType,
                    'confidence' => $verdict->confidence,
                    'matched' => $verdict->matched,
                    'root' => $verdict->root,
                    'checked_at' => now()->toIso8601String(),
                    // Stated, not implied: no installer ran and no file was
                    // written. This is a label.
                    'relabelled' => true,
                ],
            ]),
        ]);

        // Only a provisioned site has a config to republish. A pending one has
        // nothing on disk yet, and a disabled one's vhost deliberately points
        // at the disabled page — rewriting it here would put the site back
        // online as a side effect of a relabel. Same condition, and the same
        // reason, as UpdateApplication.
        if ($application->status === ApplicationStatus::Active && $application->disabled_at === null) {
            $this->vhost->execute($application->fresh(['domains', 'systemUser']));
        }

        $this->activityLogger->log('application.site_type_changed', $application, [
            'name' => $application->name,
            'from' => $from,
            'to' => $siteType,
        ]);

        return $application->fresh(['systemUser']);
    }
}
