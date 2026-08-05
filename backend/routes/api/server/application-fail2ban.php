<?php

use App\Http\Controllers\API\Server\ApplicationFail2banController;
use Illuminate\Support\Facades\Route;

// Per-application fail2ban: a different feature from the server-level
// `fail2ban` (routes/api/server/fail2ban.php) — this watches one site's own
// access log, not system auth logs. `app_fail2ban`, its own screen.
Route::get('/applications/{application}/fail2ban', [ApplicationFail2banController::class, 'show'])
    ->middleware('permission:app_fail2ban');

Route::put('/applications/{application}/fail2ban', [ApplicationFail2banController::class, 'update'])
    ->middleware(['permission:app_fail2ban,manage', 'throttle:10,1']);

Route::post('/applications/{application}/fail2ban/ban', [ApplicationFail2banController::class, 'ban'])
    ->middleware(['permission:app_fail2ban,manage', 'throttle:20,1']);

Route::delete('/applications/{application}/fail2ban/ban/{ip}', [ApplicationFail2banController::class, 'unban'])
    ->middleware(['permission:app_fail2ban,manage', 'throttle:20,1'])
    ->where('ip', '.*');
