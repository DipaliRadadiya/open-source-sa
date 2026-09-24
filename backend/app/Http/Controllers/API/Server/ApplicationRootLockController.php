<?php

namespace App\Http\Controllers\API\Server;

use App\Exceptions\FeatureException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\SiteRootLock;
use Illuminate\Http\JsonResponse;

/**
 * Whether a site's folder is locked against its own user, and the Lock button
 * for one that is not.
 *
 * Every site the panel creates is locked when it is set up. The ones that are
 * not are sites server sync adopted: somebody else created their folder, so it
 * belongs to the site user and cannot be locked as it stands. Sync never
 * changes that on its own — who owns a folder is not something a migration
 * should decide quietly — so the user is shown the state and chooses.
 */
class ApplicationRootLockController extends Controller
{
    public function show(Application $application, SiteRootLock $rootLock): JsonResponse
    {
        return response()->json(['root_lock' => $this->describe($application, $rootLock)]);
    }

    /**
     * Synchronous: a stat, a chown and a chattr. The caller gets the real
     * outcome, and a refusal says why in words the user can act on.
     */
    public function store(Application $application, SiteRootLock $rootLock, ActivityLogger $activityLogger): JsonResponse
    {
        $application->loadMissing('systemUser');

        $result = $rootLock->adopt($application);

        if ($result !== SiteRootLock::LOCKED) {
            throw new FeatureException(
                __('errors/application.root_lock.'.$result, ['path' => $application->rootPath()]),
                'root_lock',
            );
        }

        $activityLogger->log('application.root_locked', $application, [
            'name' => $application->name,
        ]);

        return response()->json(['root_lock' => $this->describe($application, $rootLock)]);
    }

    /**
     * @return array{status: string, path: string}
     */
    private function describe(Application $application, SiteRootLock $rootLock): array
    {
        $application->loadMissing('systemUser');

        return [
            // `unknown` is "could not look" (a filesystem with no immutable
            // flag, or no answer), never "unlocked".
            'status' => match ($rootLock->isLocked($application)) {
                true => 'locked',
                false => 'unlocked',
                null => 'unknown',
            },
            'path' => $application->rootPath(),
        ];
    }
}
