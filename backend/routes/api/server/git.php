<?php

use App\Http\Controllers\API\Server\GitAccountController;
use App\Http\Controllers\API\Server\GitRepositoryController;
use Illuminate\Support\Facades\Route;

// Git provider integrations. Connected accounts are managed centrally (before
// any application exists) and consumed later by git-deploy applications.
// Reads gated by `git` (view), mutations by `git` (manage).
//
// Every route here except the catalog makes an outbound call to a third
// party, so they are throttled: a slow provider must not become a way to
// occupy the panel's workers.

Route::get('/integrations/git/providers', [GitAccountController::class, 'providers'])
    ->middleware('permission:git');

Route::get('/integrations/git/accounts', [GitAccountController::class, 'index'])
    ->middleware('permission:git');

Route::post('/integrations/git/accounts', [GitAccountController::class, 'store'])
    ->middleware(['permission:git,manage', 'throttle:20,1']);

Route::put('/integrations/git/accounts/{account}', [GitAccountController::class, 'update'])
    ->middleware(['permission:git,manage', 'throttle:20,1']);

Route::post('/integrations/git/accounts/{account}/test', [GitAccountController::class, 'test'])
    ->middleware(['permission:git,manage', 'throttle:20,1']);

Route::delete('/integrations/git/accounts/{account}', [GitAccountController::class, 'destroy'])
    ->middleware('permission:git,manage');

Route::get('/integrations/git/accounts/{account}/repositories', [GitRepositoryController::class, 'repositories'])
    ->middleware(['permission:git', 'throttle:60,1']);

Route::get('/integrations/git/accounts/{account}/branches', [GitRepositoryController::class, 'branches'])
    ->middleware(['permission:git', 'throttle:60,1']);
