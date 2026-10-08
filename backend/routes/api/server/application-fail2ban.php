<?php

use App\Http\Controllers\API\Server\ApplicationFail2banBanController;
use App\Http\Controllers\API\Server\ApplicationFail2banController;
use Illuminate\Support\Facades\Route;

// Per-application fail2ban — raw INI for one jail + one filter, scoped to
// the application's own access log. A different feature from the
// server-level `fail2ban` (routes/api/server/fail2ban.php) — this watches
// one site's log, not system auth logs, and the jail lives in its own
// `sVoss-<slug>` file so it never collides with another tool's drop-ins.
Route::post('/applications/{application}/fail2ban', [ApplicationFail2banController::class, 'store'])
    ->middleware(['permission:app_fail2ban,manage', 'throttle:10,1']);

Route::get('/applications/{application}/fail2ban', [ApplicationFail2banController::class, 'show'])
    ->middleware('permission:app_fail2ban');

Route::delete('/applications/{application}/fail2ban', [ApplicationFail2banController::class, 'destroy'])
    ->middleware(['permission:app_fail2ban,manage', 'throttle:10,1']);

// FS-C45: what this application's own jail has banned, and ban/unban there,
// under the application's permission rather than the server's.
Route::get('/applications/{application}/fail2ban/bans', [ApplicationFail2banBanController::class, 'index'])
    ->middleware('permission:app_fail2ban');

Route::post('/applications/{application}/fail2ban/bans', [ApplicationFail2banBanController::class, 'store'])
    ->middleware(['permission:app_fail2ban,manage', 'throttle:20,1']);

Route::delete('/applications/{application}/fail2ban/bans/{ip}', [ApplicationFail2banBanController::class, 'destroy'])
    ->middleware(['permission:app_fail2ban,manage', 'throttle:20,1']);
