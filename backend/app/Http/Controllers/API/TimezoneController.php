<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Timezones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function index(Request $request, Timezones $timezones): JsonResponse
    {
        // `?for=php`: only the zones PHP's date.timezone accepts (FS-B8).
        return response()->json(['timezones' => $timezones->grouped($request->query('for') === 'php')]);
    }
}
