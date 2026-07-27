<?php

use App\Http\Controllers\API\Admin\PermissionCatalogController;
use Illuminate\Support\Facades\Route;

// Full permission catalog for the role create/edit form (admin-only).
Route::get('/permissions', [PermissionCatalogController::class, 'index']);
// Re-sync the catalog from code + the Administrator role (idempotent).
Route::post('/permissions/sync', [PermissionCatalogController::class, 'sync']);
