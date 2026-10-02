<?php

namespace App\Http\Controllers\API\Server\Addons;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddonRunResource;
use App\Models\AddonRun;
use App\Services\Addons\InsightHubToolkit;
use App\Services\Addons\WpToolkit;
use Illuminate\Http\JsonResponse;

/**
 * Which addon binaries this server has, and the queued runs Central polls.
 * Central-only (`central.only`).
 */
class AddonController extends Controller
{
    public function index(WpToolkit $wp, InsightHubToolkit $insighthub): JsonResponse
    {
        $addons = [];

        foreach ([$wp, $insighthub] as $addon) {
            $addons[] = [
                'name' => $addon->name(),
                'label' => $addon->label(),
                'installed' => $addon->installed(),
                'version' => $addon->version(),
            ];
        }

        return response()->json(['addons' => $addons]);
    }

    public function run(AddonRun $run): AddonRunResource
    {
        return AddonRunResource::make($run);
    }
}
