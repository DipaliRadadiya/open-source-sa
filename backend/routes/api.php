<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| One file per bounded context/domain in routes/api/*.php, required here.
| Admin-only routes live in routes/api/admin/*.php and get the extra
| "can:access-admin" authorization on top of the regular auth guard.
|
*/

foreach (glob(__DIR__.'/api/*.php') as $domainRoutes) {
    require $domainRoutes;
}

Route::middleware(['auth:sanctum', 'can:access-admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        foreach (glob(__DIR__.'/api/admin/*.php') as $adminRoutes) {
            require $adminRoutes;
        }
    });
