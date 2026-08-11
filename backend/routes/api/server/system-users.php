<?php

use App\Http\Controllers\API\Server\SystemUser\ChangeShellController;
use App\Http\Controllers\API\Server\SystemUser\SetPasswordController;
use App\Http\Controllers\API\Server\SystemUser\ShellCatalogController;
use App\Http\Controllers\API\Server\SystemUser\SshKeyController;
use App\Http\Controllers\API\Server\SystemUser\ToggleSshAccessController;
use App\Http\Controllers\API\Server\SystemUser\ToggleSudoController;
use App\Http\Controllers\API\Server\SystemUserController;
use Illuminate\Support\Facades\Route;

// System Users (server panel). View gated by `system_user` (view),
// mutations by `system_user` (manage).
Route::get('/system-users', [SystemUserController::class, 'index'])->middleware('permission:system_user');
Route::post('/system-users', [SystemUserController::class, 'store'])->middleware('permission:system_user,manage');
// Before the {systemUser} route below, not after: a wildcard registered
// first would match "shells" and try to resolve it as an id.
Route::get('/system-users/shells', ShellCatalogController::class)->middleware('permission:system_user');

Route::get('/system-users/{systemUser}', [SystemUserController::class, 'show'])->middleware('permission:system_user');
Route::delete('/system-users/{systemUser}', [SystemUserController::class, 'destroy'])->middleware('permission:system_user,manage');

// Per-user settings (single-action operations).
Route::put('/system-users/{systemUser}/password', SetPasswordController::class)->middleware('permission:system_user,manage');
Route::put('/system-users/{systemUser}/sudo', ToggleSudoController::class)->middleware('permission:system_user,manage');
Route::put('/system-users/{systemUser}/shell', ChangeShellController::class)->middleware('permission:system_user,manage');
Route::put('/system-users/{systemUser}/ssh', ToggleSshAccessController::class)->middleware('permission:system_user,manage');

// SSH keys — nested sub-resource of a system user.
Route::get('/system-users/{systemUser}/ssh-keys', [SshKeyController::class, 'index'])->middleware('permission:system_user');
Route::post('/system-users/{systemUser}/ssh-keys', [SshKeyController::class, 'store'])->middleware('permission:system_user,manage');
Route::delete('/system-users/{systemUser}/ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->middleware('permission:system_user,manage');
