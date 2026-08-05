<?php

use App\Http\Controllers\API\Server\ApplicationFileController;
use Illuminate\Support\Facades\Route;

/*
| A site's own files: reset permissions, browse/view/edit/download, upload,
| extract (.zip/.tar.gz), create a folder, rename/move, delete.
|
| Every command runs as the site's own Linux user (`runuser -u`, in
| FileBrowser), never as the panel's root — that is what makes accepting a
| client-supplied path (and, for extract, a client-supplied archive) safe
| here.
|
| Delete additionally requires `confirm: true` in the body — see
| DeleteFileRequest — as a floor against firing it by accident. It is the
| only destructive endpoint in this file.
*/

Route::post('/applications/{application}/fix-permissions', [ApplicationFileController::class, 'fixPermissions'])
    ->middleware(['permission:app_file,manage', 'throttle:5,1']);

Route::get('/applications/{application}/files', [ApplicationFileController::class, 'index'])
    ->middleware('permission:app_file');

Route::get('/applications/{application}/files/content', [ApplicationFileController::class, 'show'])
    ->middleware(['permission:app_file', 'throttle:60,1']);

Route::put('/applications/{application}/files/content', [ApplicationFileController::class, 'update'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::post('/applications/{application}/files/upload', [ApplicationFileController::class, 'upload'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

Route::get('/applications/{application}/files/download', [ApplicationFileController::class, 'download'])
    ->middleware(['permission:app_file', 'throttle:20,1']);

Route::post('/applications/{application}/files/extract', [ApplicationFileController::class, 'extract'])
    ->middleware(['permission:app_file,manage', 'throttle:5,1']);

Route::post('/applications/{application}/files/directories', [ApplicationFileController::class, 'createDirectory'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::put('/applications/{application}/files/rename', [ApplicationFileController::class, 'rename'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::delete('/applications/{application}/files', [ApplicationFileController::class, 'destroy'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);
