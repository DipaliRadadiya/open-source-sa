<?php

use App\Http\Controllers\API\Server\SettingController;
use Illuminate\Support\Facades\Route;

// Settings (server panel). Read gated by `setting` (view), each group's write
// by `setting` (manage). Detect-don't-trust: values read live, changes written
// to managed non-destructive drop-ins.
Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:setting');
Route::put('/settings/general', [SettingController::class, 'updateGeneral'])->middleware('permission:setting,manage');
Route::put('/settings/swap', [SettingController::class, 'updateSwap'])->middleware('permission:setting,manage');
Route::post('/settings/reboot', [SettingController::class, 'reboot'])->middleware('permission:setting,manage');
Route::put('/settings/security', [SettingController::class, 'updateSecurity'])->middleware('permission:setting,manage');
Route::put('/settings/updates', [SettingController::class, 'updateUpdates'])->middleware('permission:setting,manage');
Route::put('/settings/redis', [SettingController::class, 'updateRedis'])->middleware('permission:setting,manage');

// Runtimes → Node. The default version is a setting; installing and removing
// versions are operations, so they get their own routes rather than being
// squeezed into the group PUT.
Route::put('/settings/node', [SettingController::class, 'updateNode'])->middleware('permission:setting,manage');
Route::post('/settings/node/versions', [SettingController::class, 'installNodeVersion'])->middleware('permission:setting,manage');
Route::post('/settings/node/versions/{version}/npm', [SettingController::class, 'updateNodeNpm'])->middleware('permission:setting,manage');
Route::delete('/settings/node/versions/{version}', [SettingController::class, 'destroyNodeVersion'])->middleware('permission:setting,manage');

// Runtimes → PHP. Same shape as Node; apt and update-alternatives do the work
// a version manager had to do for Node. Editing a version's ini stays on the
// Services screen, next to its FPM unit.
Route::put('/settings/php', [SettingController::class, 'updatePhp'])->middleware('permission:setting,manage');
Route::post('/settings/php/versions', [SettingController::class, 'installPhpVersion'])->middleware('permission:setting,manage');
Route::delete('/settings/php/versions/{version}', [SettingController::class, 'destroyPhpVersion'])->middleware('permission:setting,manage');

// Runtimes → PHP → extensions, per version. One toggle per package: enabling
// installs it first if it is missing, disabling only unlinks it. Nothing is
// ever purged — a disabled extension costs disk, and `apt purge php8.4-*` is
// how a server loses every site at once.
Route::get('/settings/php/versions/{version}/extensions', [SettingController::class, 'phpExtensions'])->middleware('permission:setting');
Route::put('/settings/php/versions/{version}/extensions/{extension}', [SettingController::class, 'updatePhpExtension'])->middleware('permission:setting,manage');
