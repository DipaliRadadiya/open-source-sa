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
|
| `throttle:api` is removed for the same reason it is removed on the deploy
| webhook: bootstrap/app.php prepends it to every API route, and a per-route
| throttle does not replace it, it stacks. Left on, the global 120/min won
| every time — the declared 1200 and 240 were decoration, and a large upload
| competed for that budget with the UI's own polling. Verified by probing:
| the first 429 arrived at request 121 on a route declaring 240.
*/
// Registered before the `{uploadId}` route below so "space" is never taken
// for an upload id. (It would 404 there anyway — ids are 32 hex characters —
// but relying on that is a trap for whoever loosens the pattern later.)
// These five are throttled per *file*, not per byte, so the limits have to
// clear what a legitimate multi-file drop costs: begin + finalize for every
// file, an abort for every one that fails, and a status call for every chunk
// retry. At 30/min a forty-file drop throttled itself halfway through, and
// the aborts fired by the failures then used up the same budget -- the limit
// turned one failure into a stuck queue.
//
// They are cheap metadata operations (a few short commands each); the load a
// large upload actually puts on the box is in the chunk endpoint below, and
// what bounds it is the free-space guard and the size of the FPM pool, not a
// request count.
Route::get('/applications/{application}/files/uploads/space', [ApplicationUploadController::class, 'space'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_file', 'throttle:240,1']);

Route::post('/applications/{application}/files/uploads', [ApplicationUploadController::class, 'begin'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_file,manage', 'throttle:240,1']);

Route::put('/applications/{application}/files/uploads/{uploadId}', [ApplicationUploadController::class, 'chunk'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_file,manage', 'throttle:1200,1']);

Route::get('/applications/{application}/files/uploads/{uploadId}', [ApplicationUploadController::class, 'status'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_file', 'throttle:240,1']);

Route::post('/applications/{application}/files/uploads/{uploadId}/finalize', [ApplicationUploadController::class, 'finalize'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_file,manage', 'throttle:240,1']);

Route::delete('/applications/{application}/files/uploads/{uploadId}', [ApplicationUploadController::class, 'abort'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_file,manage', 'throttle:240,1']);

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
