<?php

use App\Http\Controllers\API\Server\LogController;
use Illuminate\Support\Facades\Route;

// Logs (server panel). Reading needs `logs` (view), emptying one `logs` manage. No DB: the
// source registry is resolved and files are read live. The client only sends a `key`; paths
// are resolved server-side.
Route::get('/logs', [LogController::class, 'index'])->middleware('permission:logs');
Route::get('/logs/{key}/download', [LogController::class, 'download'])->middleware('permission:logs');
Route::get('/logs/{key}', [LogController::class, 'show'])->middleware('permission:logs');

// Empty one log. `logs` **manage**, not view: reading a log and destroying it are not the
// same trust.
//
// Only sources marked `clearable` in the registry (opt-in per row; today every file-based
// source, including auth.log, ufw.log, fail2ban.log, syslog, kern.log, mail.log and the
// Let's Encrypt log). Those also carry `sensitive`, which the screen turns into a
// confirmation. {@see \App\Services\Server\LogManager} is the file to trust on this policy.
//
// The journal is not a file, so there is nothing to truncate. A refused key and an unknown
// one both answer 404: a 403 would advertise a capability the panel does not have.
Route::delete('/logs/{key}', [LogController::class, 'destroy'])->middleware('permission:logs,manage');
