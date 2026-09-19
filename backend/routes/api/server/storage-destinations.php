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

// The redirect connection for a user-owned Drive. `manage` rather than `view`:
// completing this writes a credential that can create files in somebody's
// personal Google account.
//
// The callback carries no destination in its path. Which destination an
// approval was for is sealed inside `state` and read from there, because the
// page that forwards it is a browser holding query parameters anyone could have
// written — see `GoogleOauthState`.
Route::post('/integrations/storage/destinations/{storageDestination}/oauth/start', [StorageDestinationController::class, 'oauthStart'])
    ->middleware(['permission:storage,manage', 'throttle:20,1']);

// Throttled hard. Every request here costs an outbound token exchange and a
// decrypt attempt, and the endpoint's whole job is to accept a credential — so
// it is exactly the sensitive business flow that wants a low ceiling rather
// than a generous one. A real operator reaches it once per connection.
Route::post('/integrations/storage/oauth/callback', [StorageDestinationController::class, 'oauthCallback'])
    ->middleware(['permission:storage,manage', 'throttle:10,1']);
