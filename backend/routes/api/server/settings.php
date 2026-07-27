<?php

use App\Http\Controllers\API\Server\SettingController;
use Illuminate\Support\Facades\Route;

// Settings (server panel). Read gated by `setting` (view), each group's write
// by `setting` (manage). Detect-don't-trust: values read live, changes written
// to managed non-destructive drop-ins.
Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:setting');
Route::put('/settings/general', [SettingController::class, 'updateGeneral'])->middleware('permission:setting,manage');
Route::put('/settings/security', [SettingController::class, 'updateSecurity'])->middleware('permission:setting,manage');
Route::put('/settings/updates', [SettingController::class, 'updateUpdates'])->middleware('permission:setting,manage');
Route::put('/settings/redis', [SettingController::class, 'updateRedis'])->middleware('permission:setting,manage');
