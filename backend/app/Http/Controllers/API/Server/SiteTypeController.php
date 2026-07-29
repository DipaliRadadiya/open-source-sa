<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Http\JsonResponse;

class SiteTypeController extends Controller
{
    /**
     * The application catalog — one entry per card, each carrying its own
     * field schema so the frontend renders every create form from data.
     *
     * Types this server cannot run come back with `available: false` and a
     * reason rather than being omitted, so the user can see what the box is
     * missing instead of wondering where WordPress went.
     */
    public function index(SiteTypeManager $manager): JsonResponse
    {
        return response()->json(['site_types' => $manager->catalog()]);
    }
}
