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
     * {@see UpdateSiteTypeRequest} authorised the transition; this applies it.
     *
     * Not only a column write: on OpenLiteSpeed the site type is rendered into the vhost
     * (`OlsDriver::viewData()` sets `readsHtaccess` from it), so relabelling without republishing
     * leaves the cache integration silently off. Republished for every web server, since templates
     * may read `site_type`; `ApplyVhost` rolls back a rejected config.
     *
     * @throws ProvisioningFailedException
     */
    public function execute(Application $application, string $siteType): Application
    {
        $from = (string) $application->site_type;

        $verdict = $this->detector->detect($application);

        $application->update([
            'site_type' => $siteType,
            // The evidence, kept in `settings` like brownfield adoption does: a hand-set site type
            // and an installed one are indistinguishable from the column alone.
            'settings' => array_merge($application->settings ?? [], [
                'type_detection' => [
                    'changed_from' => $from,
                    'detected' => $verdict->siteType,
                    'confidence' => $verdict->confidence,
                    'matched' => $verdict->matched,
                    'root' => $verdict->root,
                    'checked_at' => now()->toIso8601String(),
                    // No installer ran and no file was written; this is a label.
                    'relabelled' => true,
                ],
            ]),
        ]);

        // Only a provisioned site has a config to republish. A pending one has nothing on disk, and
        // rewriting a disabled one's vhost would put the site back online. Same as UpdateApplication.
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
