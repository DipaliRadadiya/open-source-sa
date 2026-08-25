<?php

use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Support\Facades\Route;

/*
| Liveness probe. Unauthenticated on purpose.
|
| An update verifies itself by calling this on localhost after switching
| releases and restarting services — at that moment there is no session and no
| token to present, and requiring one would mean the check could only ever fail.
|
| It exposes the version and nothing else: no commit, no paths, no counts. The
| version is what an update needs in order to confirm the *new* code is the
| code now answering, and it is already implied by the public changelog.
*/

Route::get('/health', function (InstalledPanelInfo $installed) {
    return response()->json([
        'health' => [
            'status' => 'ok',
            'version' => $installed->installed()['version'],
        ],
    ]);
})
    // Health retries must not share the global API bucket with browser polling
    // and other requests. Keep a dedicated public-endpoint limit instead.
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:60,1');
