<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\UpdateApplicationBotBlocker;
use App\Enums\AiBotPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\UpdateBotBlockerRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

class ApplicationBotBlockerController extends Controller
{
    /**
     * The three choices and exactly which bots each one blocks — resolved
     * server-side from `config/ai_bots.php` so the frontend never carries its
     * own copy of the bot list to drift out of sync with the one that's
     * actually enforced.
     */
    public function policies(): JsonResponse
    {
        return response()->json([
            'ai_bot_policies' => collect(AiBotPolicy::cases())->mapWithKeys(fn (AiBotPolicy $policy) => [
                $policy->value => [
                    'title' => $policy->title(),
                    'description' => $policy->description(),
                    'blocked_bots' => $policy->blockedBots(),
                    'blocked_count' => count($policy->blockedBots()),
                ],
            ]),
        ]);
    }

    public function update(UpdateBotBlockerRequest $request, Application $application, UpdateApplicationBotBlocker $action): JsonResponse
    {
        $action->execute($application, $request->policy(), $request->blocked(), $request->allowed());

        return response()->json([
            'application' => ApplicationResource::make($application->fresh(['systemUser', 'botRules']))->resolve(),
        ]);
    }
}
