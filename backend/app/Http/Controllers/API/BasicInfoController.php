<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;

class BasicInfoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'basic_info' => [
                // The same question `RegisterRequest::authorize()` asks, and it
                // has to be asked the same way. This counted *every* user
                // while registration is actually gated on non-system ones, so
                // a panel holding only machine accounts reported registration
                // closed while `POST /auth/register` would have accepted one —
                // the sign-up screen hidden on a panel with no administrator
                // and no other way in.
                'registration_open' => User::query()->where('is_system', false)->doesntExist(),
                'app_version' => config('app.version'),
                'locales_available' => config('app.available_locales'),
                'cookie_auth_enabled' => true,
                // So the sign-up form can state the requirements before the
                // user submits, rather than hardcoding its own description of
                // a rule it could not read and watching it drift.
                'password_policy' => PasswordPolicy::describe(),
            ],
        ]);
    }
}
