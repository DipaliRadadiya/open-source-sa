<?php

use App\Http\Controllers\API\Server\ApplicationLogController;
use Illuminate\Support\Facades\Route;

/*
| One application's own logs. Read-only, gated by `app_log`.
|
| Deliberately NOT the server-wide `logs` permission: a site's access log and
| the machine's auth.log are different things to be trusted with, and reusing
| one grant across that line would be privilege escalation wearing a filter.
|
| The client names a source by key; the path comes from the web-server driver,
| so no request can aim these at a file of its choosing.
*/

Route::get('/applications/{application}/logs', [ApplicationLogController::class, 'index'])
    ->middleware('permission:app_log');

Route::get('/applications/{application}/logs/{key}', [ApplicationLogController::class, 'show'])
    ->middleware(['permission:app_log', 'throttle:120,1']);

// Empty one of this site's own logs. `app_log` **manage**, not view: reading a
// log and destroying it are not the same trust.
//
// There is deliberately no equivalent for the server-wide logs. Those include
// auth.log, ufw.log and fail2ban.log — the record of what happened to the
// machine — and a one-click wipe of them is an anti-forensics button rather
// than a maintenance one. A site's access log is the site's own noise.
Route::delete('/applications/{application}/logs/{key}', [ApplicationLogController::class, 'destroy'])
    ->middleware('permission:app_log,manage');
