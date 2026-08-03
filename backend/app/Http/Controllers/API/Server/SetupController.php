<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Server\Setup\SetupCatalog;
use Illuminate\Http\JsonResponse;

/**
 * The setup page's one read.
 *
 * There is no matching write: each component carries the endpoint that installs
 * it, and those endpoints already existed. A `POST /setup/components/{key}` would
 * be a second way to trigger the same installs, and the second way is the one
 * that drifts.
 */
class SetupController extends Controller
{
    public function show(SetupCatalog $catalog): JsonResponse
    {
        return response()->json(['setup' => $catalog->toArray()]);
    }
}
