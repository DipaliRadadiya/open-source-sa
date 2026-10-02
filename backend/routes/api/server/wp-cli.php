<?php

use App\Http\Controllers\API\Server\WpCliController;
use Illuminate\Support\Facades\Route;

// wp-cli (server panel), installed from the setup page (bug #4). Gated by
// `application,manage`: whoever may create a WordPress site already makes the
// panel fetch wp-cli on the first one, so this grants nothing new.
Route::post('/wp-cli/install', [WpCliController::class, 'install'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);
