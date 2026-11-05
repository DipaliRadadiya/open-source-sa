<?php

namespace App\Http\Controllers\API\Server\Addons;

use App\Actions\Server\Addon\StartAddonLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Addons\InstallAddonRequest;
use App\Http\Resources\AddonRunResource;
use App\Models\AddonRun;
use App\Services\Addons\AddonInstaller;
use Illuminate\Http\JsonResponse;

/**
 * Install, update and remove the addon binaries, for Central. Both answer 202
 * with an AddonRun to poll at /central/addons/runs/{run}; the work is
 * AddonInstaller's, in RunAddonLifecycle.
 */
class AddonInstallController extends Controller
{
    public function __construct(private AddonInstaller $installer, private StartAddonLifecycle $start) {}

    public function store(InstallAddonRequest $request, string $addon): JsonResponse
    {
        $run = $this->start->execute($this->installer->addon($addon), AddonRun::INSTALL, $request->validated());

        return AddonRunResource::make($run)->response()->setStatusCode(202);
    }

    public function destroy(string $addon): JsonResponse
    {
        $run = $this->start->execute($this->installer->addon($addon), AddonRun::REMOVE);

        return AddonRunResource::make($run)->response()->setStatusCode(202);
    }
}
