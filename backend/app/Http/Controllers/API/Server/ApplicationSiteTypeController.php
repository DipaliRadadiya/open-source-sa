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
     * Button-triggered, not automatic: a site's files arrive after it is created, so detecting
     * on screen open would cache "nothing found" against an empty directory.
     *
     * A POST because it records its result, like
     * `POST /applications/{application}/domains/{domain}/verify`.
     */
    public function detect(
        Application $application,
        SiteTypeDetector $detector,
        SiteTypeSuggestion $suggestion,
    ): JsonResponse {
        // Authorized by `permission:application,manage` on the route (this panel has no
        // policies); `manage` because it writes the verdict.

        // A git site is not probed: its type can never be changed (see UpdateSiteTypeRequest),
        // and a git-deployed WordPress repository would record a verdict that reads like an
        // offer.
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
     * Synchronous: the write and the vhost republish take well under a second, so the caller
     * gets a real pass or failure rather than a 202.
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
