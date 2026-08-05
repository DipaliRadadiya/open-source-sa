<?php

use App\Http\Controllers\API\Server\ApplicationFileController;
use Illuminate\Support\Facades\Route;

/*
| A site's own files: reset permissions, and a read/edit-only browser. No
| create, delete, rename, upload or extract — see the file-manager research
| this repo's memory holds for why the rest is a deliberately separate
| decision, not an oversight.
|
| Every browse/read/write command runs as the site's own Linux user
| (`runuser -u`, in FileBrowser), never as the panel's root — that is what
| makes accepting a client-supplied path safe here.
*/

Route::post('/applications/{application}/fix-permissions', [ApplicationFileController::class, 'fixPermissions'])
    ->middleware(['permission:app_file,manage', 'throttle:5,1']);

Route::get('/applications/{application}/files', [ApplicationFileController::class, 'index'])
    ->middleware('permission:app_file');

Route::get('/applications/{application}/files/content', [ApplicationFileController::class, 'show'])
    ->middleware(['permission:app_file', 'throttle:60,1']);

Route::put('/applications/{application}/files/content', [ApplicationFileController::class, 'update'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::get('/applications/{application}/files/download', [ApplicationFileController::class, 'download'])
    ->middleware(['permission:app_file', 'throttle:20,1']);
