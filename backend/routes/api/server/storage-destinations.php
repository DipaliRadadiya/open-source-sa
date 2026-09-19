<?php

use App\Http\Controllers\API\Server\StorageDestinationController;
use Illuminate\Support\Facades\Route;

// Storage destinations (server panel). Connected buckets/prefixes are
// managed centrally (before any application exists) and consumed later
// by backup targets. Reads gated by `storage` (view), mutations by
// `storage` (manage).
//
// The test endpoint makes an outbound call to the destination's
// endpoint — a slow S3-compatible provider must not become a way to
// occupy the panel's workers, so it is throttled like git's `test`.

Route::get('/integrations/storage/destinations', [StorageDestinationController::class, 'index'])
    ->middleware('permission:storage');

Route::post('/integrations/storage/destinations', [StorageDestinationController::class, 'store'])
    ->middleware(['permission:storage,manage', 'throttle:20,1']);

Route::get('/integrations/storage/destinations/{storageDestination}', [StorageDestinationController::class, 'show'])
    ->middleware('permission:storage');

Route::patch('/integrations/storage/destinations/{storageDestination}', [StorageDestinationController::class, 'update'])
    ->middleware(['permission:storage,manage', 'throttle:20,1']);

Route::delete('/integrations/storage/destinations/{storageDestination}', [StorageDestinationController::class, 'destroy'])
    ->middleware('permission:storage,manage');

Route::post('/integrations/storage/destinations/{storageDestination}/test', [StorageDestinationController::class, 'test'])
    ->middleware(['permission:storage,manage', 'throttle:20,1']);

// The device-flow connection for a user-owned Drive. `manage` rather than
// `view`: approving this writes a credential that can create files in
// somebody's personal Google account.
//
// Polling is rate-limited generously because the browser genuinely does poll —
// Google's own interval is about five seconds and a code lives half an hour,
// so a patient operator makes a few hundred legitimate requests.
Route::post('/integrations/storage/destinations/{storageDestination}/oauth/start', [StorageDestinationController::class, 'oauthStart'])
    ->middleware(['permission:storage,manage', 'throttle:20,1']);

Route::post('/integrations/storage/destinations/{storageDestination}/oauth/poll', [StorageDestinationController::class, 'oauthPoll'])
    ->middleware(['permission:storage,manage', 'throttle:600,10']);
