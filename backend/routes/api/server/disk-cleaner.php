<?php

use App\Http\Controllers\API\Server\DiskCleanerController;
use Illuminate\Support\Facades\Route;

// Disk Cleaner (server panel). Preview gated by `disk_cleaner` (view), the
// destructive clean by `disk_cleaner` (manage). No DB — live df + estimates.
Route::get('/disk-cleaner', [DiskCleanerController::class, 'index'])->middleware('permission:disk_cleaner');
Route::post('/disk-cleaner/clean', [DiskCleanerController::class, 'clean'])->middleware('permission:disk_cleaner,manage');
