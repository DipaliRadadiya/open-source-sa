<?php

use App\Http\Controllers\API\Server\ApplicationFileController;
use App\Http\Controllers\API\Server\ApplicationUploadController;
use Illuminate\Support\Facades\Route;

/*
| A site's own files: permissions, browse/view/edit/download, upload, extract, rename/move,
| delete, search and folder size.
|
| Every command runs as the site's own Linux user (`runuser -u`, in FileBrowser), never as the
| panel's root: that is what makes accepting a client-supplied path or archive safe.
|
| Delete also requires `confirm: true` in the body (see DeleteFileRequest).
*/

Route::post('/applications/{application}/fix-permissions', [ApplicationFileController::class, 'fixPermissions'])
    ->middleware(['permission:app_file,manage', 'throttle:5,1']);

Route::get('/applications/{application}/files', [ApplicationFileController::class, 'index'])
    ->middleware('permission:app_file');

Route::get('/applications/{application}/files/search', [ApplicationFileController::class, 'search'])
    ->middleware(['permission:app_file', 'throttle:10,1']);

/*
| Bytes by file type, for the file manager chart. Throttled harder than the listing: it walks
| every file under the target.
*/
Route::get('/applications/{application}/files/breakdown', [ApplicationFileController::class, 'breakdown'])
    ->middleware(['permission:app_file', 'throttle:30,1']);

Route::get('/applications/{application}/files/size', [ApplicationFileController::class, 'folderSize'])
    ->middleware(['permission:app_file', 'throttle:20,1']);

// Every folder size in a directory at once. Cached for five minutes, so the limit is for
// `refresh=1`, not for browsing.
Route::get('/applications/{application}/files/sizes', [ApplicationFileController::class, 'folderSizes'])
    ->middleware(['permission:app_file', 'throttle:60,1']);

// Reading a file's contents (open, download, preview) needs manage; `view` is browse, search and
// sizes only. Otherwise a view-only role could read wp-config.php or .env, secrets that every
// other screen hides from it.
Route::get('/applications/{application}/files/content', [ApplicationFileController::class, 'show'])
    ->middleware(['permission:app_file,manage', 'throttle:60,1']);

Route::put('/applications/{application}/files/content', [ApplicationFileController::class, 'update'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::post('/applications/{application}/files/content/restore', [ApplicationFileController::class, 'restoreBackup'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

Route::post('/applications/{application}/files/upload', [ApplicationFileController::class, 'upload'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

/*
| Resumable uploads, for files too big for the single-shot endpoint above to buffer in PHP
| memory. The chunk endpoint takes a **raw body**, not multipart (see ApplicationUploadController).
|
| Its throttle is per chunk, not per file: one large upload is thousands of requests. The real
| bounds are nginx's client_max_body_size (one chunk) and ChunkedUpload's free-space guard.
|
| `throttle:api` is removed here as on the deploy webhook: bootstrap/app.php prepends it to
| every API route and a per-route throttle stacks with it instead of replacing it, so the
| global 120/min would always win.
*/
// Registered before the `{uploadId}` route so "space" is never taken for an upload id.
// These are throttled per *file*, so the limits must clear a multi-file drop: begin + finalize
// per file, an abort per failure, a status call per chunk retry. They are cheap metadata calls;
// the real load is in the chunk endpoint.
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
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

/*
| Returns an image with its real content type, so the file manager can show it. Manage, like
| `content` and `download`; throttled like `content`, since a folder of thumbnails is many
| small reads.
*/
Route::get('/applications/{application}/files/preview', [ApplicationFileController::class, 'preview'])
    ->middleware(['permission:app_file,manage', 'throttle:60,1']);

Route::post('/applications/{application}/files/extract', [ApplicationFileController::class, 'extract'])
    ->middleware(['permission:app_file,manage', 'throttle:5,1']);

// Trash: deleting moves files here by default.
Route::get('/applications/{application}/files/trash', [ApplicationFileController::class, 'trash'])
    ->middleware(['permission:app_file', 'throttle:60,1']);

Route::post('/applications/{application}/files/trash/restore', [ApplicationFileController::class, 'restoreTrash'])
    ->middleware(['permission:app_file,manage', 'throttle:30,1']);

// The one unrecoverable action here: it is how the disk space comes back.
Route::delete('/applications/{application}/files/trash', [ApplicationFileController::class, 'emptyTrash'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

Route::post('/applications/{application}/files/directories', [ApplicationFileController::class, 'createDirectory'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::put('/applications/{application}/files/rename', [ApplicationFileController::class, 'rename'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::post('/applications/{application}/files/copy', [ApplicationFileController::class, 'copy'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

Route::post('/applications/{application}/files/compress', [ApplicationFileController::class, 'compress'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);

// Polled while an archive job runs; `view` is enough, since watching is not changing.
// Outside the global limiter, on `throttle:progress`, like the other polling endpoints: a
// per-route throttle stacks with `throttle:api`, so polling would eat the user's global budget.
Route::get('/applications/{application}/files/archive-jobs', [ApplicationFileController::class, 'archiveJobs'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_file,view', 'throttle:progress']);

Route::put('/applications/{application}/files/permissions', [ApplicationFileController::class, 'chmod'])
    ->middleware(['permission:app_file,manage', 'throttle:20,1']);

Route::delete('/applications/{application}/files', [ApplicationFileController::class, 'destroy'])
    ->middleware(['permission:app_file,manage', 'throttle:10,1']);
