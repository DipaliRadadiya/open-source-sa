<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Server\Settings\SystemUpdatesActivity;
use App\Services\Server\Setup\SetupCatalog;
use Illuminate\Http\JsonResponse;

/**
 * The setup page's one read.
 *
 * There is no matching write: each component carries the endpoint that installs
 * it, and those endpoints already existed. A `POST /setup/components/{key}` would
 * be a second way to trigger the same installs, and the second way is the one
 * that drifts.
 *
 * `system_updates_running`: Ubuntu is installing its own updates, so anything
 * installed from here waits for them first. Usual for a few minutes after a
 * fresh install; the page says so instead of looking stuck.
 */
class SetupController extends Controller
{
    public function show(SetupCatalog $catalog, SystemUpdatesActivity $updates): JsonResponse
    {
        return response()->json(['setup' => [
            ...$catalog->toArray(),
            'system_updates_running' => $updates->running(),
        ]]);
    }
}
