<?php

use App\Http\Controllers\API\Server\ApplicationController;
use App\Http\Controllers\API\Server\ApplicationWebhookController;
use App\Http\Controllers\API\Server\ServerCapabilityController;
use App\Http\Controllers\API\Server\SiteTypeController;
use Illuminate\Support\Facades\Route;

// Applications (server panel). Reads gated by `application` (view), mutations
// by `application` (manage).
//
// Phase 1: the catalog and the record only — nothing here writes to the
// server. A created application stays at `pending` until provisioning lands.

// What the server is and can run — drives which site types are offered.
Route::get('/server/capabilities', [ServerCapabilityController::class, 'index'])->middleware('permission:application');

Route::get('/site-types', [SiteTypeController::class, 'index'])->middleware('permission:application');

Route::get('/applications', [ApplicationController::class, 'index'])->middleware('permission:application');
Route::post('/applications', [ApplicationController::class, 'store'])->middleware('permission:application,manage');
Route::get('/applications/port-check', [ApplicationController::class, 'portCheck'])
    ->middleware('permission:application');
Route::get('/applications/{application}', [ApplicationController::class, 'show'])->middleware('permission:application');
Route::put('/applications/{application}', [ApplicationController::class, 'update'])->middleware('permission:application,manage');
Route::post('/applications/{application}/provision', [ApplicationController::class, 'provision'])->middleware('permission:application,manage');
Route::post('/applications/{application}/deploy', [ApplicationController::class, 'deploy'])->middleware('permission:application,manage');
Route::post('/applications/{application}/process/{action}', [ApplicationController::class, 'process'])
    ->middleware('permission:application,manage');

// Deploy-on-push. The delivery endpoint itself is unauthenticated and lives in
// routes/api/webhooks.php; these two only configure it.
Route::get('/webhook-providers', [ApplicationWebhookController::class, 'providers'])
    ->middleware('permission:application');
Route::put('/applications/{application}/webhook', [ApplicationWebhookController::class, 'update'])
    ->middleware('permission:application,manage');
Route::delete('/applications/{application}', [ApplicationController::class, 'destroy'])->middleware('permission:application,manage');
