<?php

use App\Http\Controllers\API\Admin\DoctorController;
use Illuminate\Support\Facades\Route;

/*
| Installation self-check — administrator only.
|
| Throttled because each call shells out to sudo and systemctl and makes an
| outbound HTTP request to the panel's own health endpoint. It is a diagnostic,
| not something to poll.
*/

Route::get('/doctor', [DoctorController::class, 'show'])
    ->middleware('throttle:10,1');
