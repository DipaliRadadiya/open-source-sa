<?php

use App\Http\Controllers\API\Server\Fail2banController;
use Illuminate\Support\Facades\Route;

// fail2ban (server panel). View gated by `fail2ban` (view), mutations by
// `fail2ban` (manage). No DB — live state comes from fail2ban-client and the
// managed settings from a jail.d drop-in.
// Carries install progress now, so it is polled while apt runs — up to ten
// minutes. Outside the global limiter for the same reason as the other
// progress feeds.
Route::get('/fail2ban', [Fail2banController::class, 'index'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:fail2ban', 'throttle:progress']);
Route::post('/fail2ban/install', [Fail2banController::class, 'install'])->middleware('permission:fail2ban,manage');
Route::put('/fail2ban', [Fail2banController::class, 'update'])->middleware('permission:fail2ban,manage');
Route::post('/fail2ban/bans', [Fail2banController::class, 'ban'])->middleware('permission:fail2ban,manage');
// Static route before the {ip} binding.
Route::delete('/fail2ban/bans', [Fail2banController::class, 'unbanAll'])->middleware('permission:fail2ban,manage');
Route::delete('/fail2ban/bans/{ip}', [Fail2banController::class, 'unban'])->middleware('permission:fail2ban,manage');
