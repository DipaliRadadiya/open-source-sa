<?php

namespace App\Http\Controllers\API\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\ShowBotTrafficRequest;
use App\Models\Application;
use App\Services\Server\Applications\BotTrafficReporter;
use Illuminate\Http\JsonResponse;

class ApplicationBotTrafficController extends Controller
{
    /**
     * Which bots hit this site, and which of them the current settings block.
     *
     * Gated by `app_log` rather than `app_bot_blocker`: this reads the site's
     * access log, and letting the bot-blocker grant read logs would quietly
     * turn a narrow permission into a wider one.
     */
    public function show(ShowBotTrafficRequest $request, Application $application, BotTrafficReporter $reporter): JsonResponse
    {
        return response()->json([
            'bot_traffic' => $reporter->report(
                $application,
                (int) ($request->validated('days') ?? BotTrafficReporter::DEFAULT_DAYS),
            ),
        ]);
    }
}
