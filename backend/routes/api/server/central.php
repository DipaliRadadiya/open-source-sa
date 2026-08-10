<?php

use App\Http\Controllers\API\Server\CentralController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Management Connection
|--------------------------------------------------------------------------
|
| Endpoints for the OSS admin to enable/disable/query the central panel
| connection. These require a normal authenticated session (Sanctum).
|
| POST   /central/enable   — generate and store a new token
| GET    /central/status   — return masked token status
| DELETE /central          — revoke the current token
|
| Additionally, any route protected by the 'central' middleware will accept
| an Authorization: Bearer <token> header as an alternative to Sanctum
| session auth. Central uses this to call the OSS API on the server.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/central/enable', [CentralController::class, 'enable']);
    Route::get('/central/status', [CentralController::class, 'status']);
    Route::delete('/central', [CentralController::class, 'disable']);
});
