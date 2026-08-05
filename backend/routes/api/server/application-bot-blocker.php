<?php

use App\Http\Controllers\API\Server\ApplicationBotBlockerController;
use Illuminate\Support\Facades\Route;

// AI Bot Blocker: three plain-language choices, each resolving server-side
// to the bot list that actually gets 403'd. Its own screen and permission
// (`app_bot_blocker`), same shape as Password Protection (`app_security`).
Route::get('/ai-bot-policies', [ApplicationBotBlockerController::class, 'policies'])
    ->middleware('permission:app_bot_blocker');

Route::put('/applications/{application}/bot-blocker', [ApplicationBotBlockerController::class, 'update'])
    ->middleware(['permission:app_bot_blocker,manage', 'throttle:10,1']);
