<?php

use App\Http\Controllers\API\Server\LogController;
use Illuminate\Support\Facades\Route;

// Logs (server panel). Read-only; all gated by `logs` (view). No DB — the
// source registry is resolved and files are read live from disk. The client
// only ever references a source by `key`; paths are resolved server-side.
Route::get('/logs', [LogController::class, 'index'])->middleware('permission:logs');
Route::get('/logs/{key}/download', [LogController::class, 'download'])->middleware('permission:logs');
Route::get('/logs/{key}', [LogController::class, 'show'])->middleware('permission:logs');

// Empty one log. `logs` **manage**, not view: reading a log and destroying it
// are not the same trust.
//
// Only the sources marked `clearable` in the registry, which is opt-in per row.
// auth.log, ufw.log, fail2ban.log, syslog, kern.log, mail.log, the Let's
// Encrypt log and the journal are deliberately excluded — they record what
// happened to the machine, which is what an investigation needs and the first
// thing an intruder would erase. A 404 for those, the same answer as a key that
// does not exist, because from the client's side they are equally not offered.
Route::delete('/logs/{key}', [LogController::class, 'destroy'])->middleware('permission:logs,manage');
