<?php

use App\Http\Controllers\API\TimezoneController;
use Illuminate\Support\Facades\Route;

// A reference list rather than a feature resource — it is not under
// /settings, because settings is only one of the places that needs it
// (cronjob schedules and backup windows will too).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/timezones', [TimezoneController::class, 'index']);
});
