<?php

use App\Http\Controllers\API\Server\DiskCleanerController;
use App\Http\Controllers\API\Server\DiskCleanerRunController;
use App\Http\Controllers\API\Server\DiskCleanerScheduleController;
use Illuminate\Support\Facades\Route;

// Disk Cleaner (server panel). Preview/history gated by `disk_cleaner` (view),
// the destructive clean + schedule changes by `disk_cleaner` (manage).
Route::get('/disk-cleaner', [DiskCleanerController::class, 'index'])->middleware('permission:disk_cleaner');
Route::post('/disk-cleaner/clean', [DiskCleanerController::class, 'clean'])->middleware('permission:disk_cleaner,manage');

// Automatic cleaner: the schedule is a DB profile (single source of truth) run
// by the Laravel scheduler — no cron file, so it never drifts with Cronjobs.
Route::get('/disk-cleaner/schedule', [DiskCleanerScheduleController::class, 'show'])->middleware('permission:disk_cleaner');
Route::put('/disk-cleaner/schedule', [DiskCleanerScheduleController::class, 'update'])->middleware('permission:disk_cleaner,manage');
Route::delete('/disk-cleaner/schedule', [DiskCleanerScheduleController::class, 'destroy'])->middleware('permission:disk_cleaner,manage');
Route::get('/disk-cleaner/runs', [DiskCleanerRunController::class, 'index'])->middleware('permission:disk_cleaner');
