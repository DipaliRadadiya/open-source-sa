<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\ChangeSiteType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateSiteTypeRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\Applications\SiteTypeDetector;
use App\Services\Applications\SiteTypeSuggestion;
use Illuminate\Http\JsonResponse;

class ApplicationSiteTypeController extends Controller
{
    /**
     * Read the disk and say what is installed here.
     *
     * Button-triggered, not automatic. A site's files arrive *after* it is
     * created — the user makes a Custom PHP site and then uploads WordPress
     * into it — so detecting when the screen is opened would run against an
     * empty directory and cache "nothing found" at the one moment the answer
     * is guaranteed to be wrong. The user clicks Detect when they are ready.
     *
     * A probe, and it records its result, which is why it is a POST: it
     * mirrors `POST /applications/{application}/domains/{domain}/verify`, the
     * panel's existing shape for exactly this.
     */
    public function detect(
        Application $application,
        SiteTypeDetector $detector,
        SiteTypeSuggestion $suggestion,
    ): JsonResponse {
        // Authorized by `permission:application,manage` on the route, the way
        // every other application endpoint here is — this panel has no
        // policies. `manage` rather than the read permission because it writes
        // the verdict, and because its only purpose is to enable a change.

        // A git site is not probed at all. Its type can never be changed (see
        // UpdateSiteTypeRequest), so every verdict the probe could return is
        // one nothing may act on — and a git-deployed WordPress repository
        // matches `wp-config.php` at 95, so what would be stored is a finding
        // that reads like an offer. Suppressing the suggestion downstream
        // would be enough for the screen and would still leave that verdict
        // recorded against the site for the next person to read as one.
        if ((string) $application->site_type === 'git') {
            return response()->json([
                'site_type_detection' => $suggestion->describe($application),
            ]);
        }

        $verdict = $detector->detect($application);

        $application->update([
            'settings' => array_merge($application->settings ?? [], [
                'type_detection' => [
                    'detected' => $verdict->siteType,
                    'confidence' => $verdict->confidence,
                    'matched' => $verdict->matched,
                    'root' => $verdict->root,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        return response()->json([
            'site_type_detection' => $suggestion->describe($application->fresh(), $verdict),
        ]);
    }

    /**
     * Apply a new site type.
     *
     * Synchronous: the write and the vhost republish take well under a second,
     * and the caller gets a real pass or a real failure rather than a 202 and
     * a label that quietly never changed.
     */
    public function update(
        UpdateSiteTypeRequest $request,
        Application $application,
        ChangeSiteType $action,
    ): JsonResponse {
        return response()->json([
            'application' => ApplicationResource::make(
                $action->execute($application, $request->validated('site_type')),
            )->resolve(),
        ]);
    }
}
