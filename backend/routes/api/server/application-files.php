<?php

use App\Http\Controllers\API\Server\ApplicationFileController;
use App\Http\Controllers\API\Server\ApplicationUploadController;
use Illuminate\Support\Facades\Route;

/*
| A site's own files: reset permissions, browse/view/edit/download, upload,
| extract (.zip/.tar.gz), create a folder, rename/move, delete, recursive
| search, and on-demand folder size.
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

Route::get('/applications/{application}/files/search', [ApplicationFileController::class, 'search'])
    ->middleware(['permission:app_file', 'throttle:10,1']);

Route::get('/applications/{application}/files/size', [ApplicationFileController::class, 'folderSize'])
    ->middleware(['permission:app_file', 'throttle:20,1']);

Route::get('/applications/{application}/files/content', [ApplicationFileController::class, 'show'])
    ->middleware(['permission:app_file', 'throttle:60,1']);

Route::put('/applications/{application}/files/content', [ApplicationFileController::class, 'update'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::post('/applications/{application}/files/content/restore', [ApplicationFileController::class, 'restoreBackup'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

Route::post('/applications/{application}/files/upload', [ApplicationFileController::class, 'upload'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

/*
| Resumable uploads, for files past what the single-shot endpoint above can
| buffer through PHP memory. `uploads` (plural) rather than a nested path
| under `upload`, so neither route is a prefix of the other.
|
| The chunk endpoint takes a **raw body**, not multipart — see
| ApplicationUploadController for why that halves the disk traffic of an
| upload on a box that is also serving customer sites.
|
| Its throttle is per chunk, not per file: one large upload is legitimately
| thousands of requests, so the usual 10/min here would cap throughput at a
| few hundred MB an hour. The real bounds on this endpoint are nginx's
| client_max_body_size (one chunk) and ChunkedUpload's free-space guard.
*/
// Registered before the `{uploadId}` route below so "space" is never taken
// for an upload id. (It would 404 there anyway — ids are 32 hex characters —
// but relying on that is a trap for whoever loosens the pattern later.)
Route::get('/applications/{application}/files/uploads/space', [ApplicationUploadController::class, 'space'])
    ->middleware(['permission:app_file', 'throttle:60,1']);

Route::post('/applications/{application}/files/uploads', [ApplicationUploadController::class, 'begin'])
    ->middleware(['permission:app_file,manage', 'throttle:30,1']);

Route::put('/applications/{application}/files/uploads/{uploadId}', [ApplicationUploadController::class, 'chunk'])
    ->middleware(['permission:app_file,manage', 'throttle:1200,1']);

Route::get('/applications/{application}/files/uploads/{uploadId}', [ApplicationUploadController::class, 'status'])
    ->middleware(['permission:app_file', 'throttle:60,1']);

Route::post('/applications/{application}/files/uploads/{uploadId}/finalize', [ApplicationUploadController::class, 'finalize'])
    ->middleware(['permission:app_file,manage', 'throttle:30,1']);

Route::delete('/applications/{application}/files/uploads/{uploadId}', [ApplicationUploadController::class, 'abort'])
    ->middleware(['permission:app_file,manage', 'throttle:30,1']);

Route::get('/applications/{application}/files/download', [ApplicationFileController::class, 'download'])
    ->middleware(['permission:app_file', 'throttle:20,1']);

Route::post('/applications/{application}/files/extract', [ApplicationFileController::class, 'extract'])
    ->middleware(['permission:app_file,manage', 'throttle:5,1']);

Route::post('/applications/{application}/files/directories', [ApplicationFileController::class, 'createDirectory'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::put('/applications/{application}/files/rename', [ApplicationFileController::class, 'rename'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::post('/applications/{application}/files/copy', [ApplicationFileController::class, 'copy'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

Route::post('/applications/{application}/files/compress', [ApplicationFileController::class, 'compress'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

Route::put('/applications/{application}/files/permissions', [ApplicationFileController::class, 'chmod'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::delete('/applications/{application}/files', [ApplicationFileController::class, 'destroy'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);
