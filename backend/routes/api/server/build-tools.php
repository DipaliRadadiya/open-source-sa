<?php

use App\Http\Controllers\API\Server\BuildToolsController;
use Illuminate\Support\Facades\Route;

// Build tools (server panel). The compiler toolchain npm falls back to when a
// package ships no prebuilt binary for the site's Node version. No DB —
// presence is detected live from the binaries on PATH.
//
// Gated by `node` rather than a permission of its own, because in this panel
// the toolchain exists for exactly one reason: npm compiling a native module.
// PHP extensions are apt packages and never compile, and composer resolves
// rather than builds — so "may manage Node" and "may install what Node needs
// to build" are the same permission, and inventing a second one would leave a
// button that 403s for every role nobody thought to update.
//
// If something outside Node ever needs the compiler, that is the moment to
// split this out — not before.
//
// Carries install progress, so it is polled while apt runs — up to fifteen
// minutes. Outside the global limiter for the same reason as the other
// progress feeds.
Route::get('/build-tools', [BuildToolsController::class, 'index'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:node', 'throttle:progress']);
Route::post('/build-tools/install', [BuildToolsController::class, 'install'])
    ->middleware('permission:node,manage');
