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

// A plain scheduled reboot — daily, weekly or monthly, whether or not an
// update asked for one. Separate from the `updates` group, which is
// unattended-upgrades' reboot-when-required and has no frequency at all.
Route::get('/settings/reboot-schedule/presets', [SettingController::class, 'rebootSchedulePresets'])->middleware('permission:setting');
Route::put('/settings/reboot-schedule', [SettingController::class, 'updateRebootSchedule'])->middleware('permission:setting,manage');
