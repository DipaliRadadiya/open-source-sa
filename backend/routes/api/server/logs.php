<?php

use App\Http\Controllers\API\Server\LogController;
use Illuminate\Support\Facades\Route;

// Logs (server panel). Reading is gated by `logs` (view); emptying one is
// `logs` manage, below. No DB — the source registry is resolved and files are
// read live from disk. The client only ever references a source by `key`;
// paths are resolved server-side.
Route::get('/logs', [LogController::class, 'index'])->middleware('permission:logs');
Route::get('/logs/{key}/download', [LogController::class, 'download'])->middleware('permission:logs');
Route::get('/logs/{key}', [LogController::class, 'show'])->middleware('permission:logs');

// Empty one log. `logs` **manage**, not view: reading a log and destroying it
// are not the same trust.
//
// Only the sources marked `clearable` in the registry, which is opt-in per row
// and today covers every file-based source — including the ones that record
// what happened to the machine: auth.log, ufw.log, fail2ban.log, syslog,
// kern.log, mail.log and the Let's Encrypt log. Those carry `sensitive` too,
// which the screen turns into a confirmation naming what is about to be
// destroyed rather than a refusal. Operator's decision, taken after the
// alternative was argued — see {@see \App\Services\Server\LogManager}, which
// is the file to trust on this policy.
//
// ⚠️ This comment said the opposite until 2026-09-15: that those seven were
// "deliberately excluded" and answered 404. That was the original policy and it
// changed; the comment did not. Anyone reading only this file was told the
// wrong thing about what the endpoint does.
//
// The journal is the one refusal left, and it is technical rather than policy:
// it is not a file, so there is nothing to truncate. A refused key and an
// unknown one both answer 404 — from the client's side they are equally not on
// offer, and a 403 would advertise a capability the panel does not intend to
// have.
Route::delete('/logs/{key}', [LogController::class, 'destroy'])->middleware('permission:logs,manage');
