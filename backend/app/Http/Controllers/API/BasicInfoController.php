<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BasicInfoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'basic_info' => [
                'registration_open' => User::query()->doesntExist(),
                'app_version' => config('app.version'),
                'locales_available' => config('app.available_locales'),
                'cookie_auth_enabled' => true,
            ],
        ]);
    }
}
