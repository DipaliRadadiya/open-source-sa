<?php

use App\Http\Controllers\API\Server\ApplicationWafController;
use Illuminate\Support\Facades\Route;

// 8G Firewall: six independently switchable rule categories, a detect vs
// enforce mode, and a per-app exceptions/custom-rules list — its own screen
// and permission (`app_firewall`), same shape as Password Protection and
// the AI Bot Blocker.
Route::get('/waf-options', [ApplicationWafController::class, 'options'])
    ->middleware('permission:app_firewall');

Route::get('/applications/{application}/waf', [ApplicationWafController::class, 'show'])
    ->middleware('permission:app_firewall');

Route::put('/applications/{application}/waf', [ApplicationWafController::class, 'update'])
    ->middleware(['permission:app_firewall,manage', 'throttle:10,1']);
