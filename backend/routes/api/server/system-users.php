<?php

use App\Http\Controllers\API\Server\SystemUser\SshKeyController;
use App\Http\Controllers\API\Server\SystemUserController;
use Illuminate\Support\Facades\Route;

// System Users (server panel). View gated by `system_user` (view),
// mutations by `system_user` (manage).
Route::get('/system-users', [SystemUserController::class, 'index'])->middleware('permission:system_user');
Route::post('/system-users', [SystemUserController::class, 'store'])->middleware('permission:system_user,manage');
Route::get('/system-users/{systemUser}', [SystemUserController::class, 'show'])->middleware('permission:system_user');
Route::delete('/system-users/{systemUser}', [SystemUserController::class, 'destroy'])->middleware('permission:system_user,manage');

// SSH keys — nested sub-resource of a system user.
Route::get('/system-users/{systemUser}/ssh-keys', [SshKeyController::class, 'index'])->middleware('permission:system_user');
Route::post('/system-users/{systemUser}/ssh-keys', [SshKeyController::class, 'store'])->middleware('permission:system_user,manage');
Route::delete('/system-users/{systemUser}/ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->middleware('permission:system_user,manage');
