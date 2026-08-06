<?php

use App\Http\Controllers\API\Server\ApplicationBotBlockerController;
use App\Http\Controllers\API\Server\ApplicationBotTrafficController;
use Illuminate\Support\Facades\Route;

// AI Bot Blocker: three plain-language choices, each resolving server-side
// to the bot list that actually gets 403'd. Its own screen and permission
// (`app_bot_blocker`), same shape as Password Protection (`app_security`).
Route::get('/ai-bot-policies', [ApplicationBotBlockerController::class, 'policies'])
    ->middleware('permission:app_bot_blocker');

Route::put('/applications/{application}/bot-blocker', [ApplicationBotBlockerController::class, 'update'])
    ->middleware(['permission:app_bot_blocker,manage', 'throttle:10,1']);

// Which bots actually hit this site — what turns the policy choice from a
// guess into an evidenced decision. Gated by `app_log`, not
// `app_bot_blocker`: it reads the site's access log, and reusing the bot
// grant here would widen it into a log-reading grant.
Route::get('/applications/{application}/bot-traffic', [ApplicationBotTrafficController::class, 'show'])
    ->middleware(['permission:app_log', 'throttle:30,1']);
