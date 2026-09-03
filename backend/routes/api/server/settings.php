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
// Read before write: whether a restart is already pending, and for when. Read
// from systemd rather than remembered, so a reboot scheduled from a shell is
// not invisible to the panel.
Route::get('/settings/reboot', [SettingController::class, 'rebootStatus'])->middleware('permission:setting');
// `shutdown -c`. Without this a restart scheduled an hour out could be watched
// and not stopped.
Route::delete('/settings/reboot', [SettingController::class, 'cancelReboot'])->middleware('permission:setting,manage');
Route::put('/settings/security', [SettingController::class, 'updateSecurity'])->middleware('permission:setting,manage');
Route::put('/settings/mysql', [SettingController::class, 'updateMysql'])->middleware('permission:setting,manage');
Route::put('/settings/mysql-binlog', [SettingController::class, 'updateMysqlBinlog'])->middleware('permission:setting,manage');
Route::post('/settings/mysql-binlog/purge', [SettingController::class, 'purgeMysqlBinlog'])->middleware('permission:setting,manage');
Route::put('/settings/updates', [SettingController::class, 'updateUpdates'])->middleware('permission:setting,manage');
Route::put('/settings/redis', [SettingController::class, 'updateRedis'])->middleware('permission:setting,manage');

// A plain scheduled reboot — daily, weekly or monthly, whether or not an
// update asked for one. Separate from the `updates` group, which is
// unattended-upgrades' reboot-when-required and has no frequency at all.
Route::get('/settings/reboot-schedule/presets', [SettingController::class, 'rebootSchedulePresets'])->middleware('permission:setting');
Route::put('/settings/reboot-schedule', [SettingController::class, 'updateRebootSchedule'])->middleware('permission:setting,manage');
