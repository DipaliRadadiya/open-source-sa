<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\UpdateApplicationFail2ban;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\BanApplicationIpRequest;
use App\Http\Requests\Server\Application\UpdateFail2banRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationFail2banManager;
use Illuminate\Http\JsonResponse;

class ApplicationFail2banController extends Controller
{
    public function show(Application $application, ApplicationFail2banManager $manager): JsonResponse
    {
        return response()->json([
            'fail2ban_enabled' => (bool) $application->fail2ban_enabled,
            'jails' => $manager->status($application),
        ]);
    }

    public function update(UpdateFail2banRequest $request, Application $application, UpdateApplicationFail2ban $action): JsonResponse
    {
        $action->execute($application, $request->enabled());

        return response()->json([
            'application' => ApplicationResource::make($application->fresh())->resolve(),
        ]);
    }

    public function ban(BanApplicationIpRequest $request, Application $application, ApplicationFail2banManager $manager): JsonResponse
    {
        $manager->ban($application, $request->ip());

        return response()->json(['banned' => $request->ip()]);
    }

    public function unban(Application $application, string $ip, ApplicationFail2banManager $manager): JsonResponse
    {
        abort_unless(filter_var($ip, FILTER_VALIDATE_IP) !== false, 422);

        $manager->unban($application, $ip);

        return response()->json(['unbanned' => $ip]);
    }
}
