<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Timezones;
use Illuminate\Http\JsonResponse;

class TimezoneController extends Controller
{
    /**
     * The timezones the panel accepts, grouped by region.
     *
     * Authenticated but **not permission-gated**: this is a reference list,
     * not a resource. Server settings, cronjob schedules and backup windows
     * all need it, and gating it on any one of those permissions would hide
     * it from the others.
     */
    public function index(Timezones $timezones): JsonResponse
    {
        return response()->json(['timezones' => $timezones->grouped()]);
    }
}
