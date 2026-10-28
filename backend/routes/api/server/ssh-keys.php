<?php

use App\Http\Controllers\API\Server\SshKeysController;
use Illuminate\Support\Facades\Route;

// SSH keys across all system users (central's key list, 2026-10-28). Same
// permission as the per-user key routes in system-users.php.
Route::get('/ssh-keys', [SshKeysController::class, 'index'])->middleware('permission:system_user');
Route::post('/ssh-keys/deploy', [SshKeysController::class, 'deploy'])->middleware(['permission:system_user,manage', 'throttle:30,1']);
Route::delete('/ssh-keys', [SshKeysController::class, 'destroy'])->middleware(['permission:system_user,manage', 'throttle:30,1']);
